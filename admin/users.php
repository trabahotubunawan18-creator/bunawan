<?php
require __DIR__ . '/../includes/admin_auth.php';
require_admin();
$pdo = db();

$tabs = ['all' => 'All', 'seeker' => 'Job seekers', 'employer' => 'Employers', 'suspended' => 'Suspended', 'admin' => 'Admins'];
$tab = array_key_exists($_GET['tab'] ?? '', $tabs) ? $_GET['tab'] : 'all';
$q = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$page = max(1, (int)($_GET['page'] ?? 1)); $per = 15;
$back = 'admin/users.php?' . http_build_query(array_filter(['tab' => $tab !== 'all' ? $tab : '', 'q' => $q, 'page' => $page > 1 ? $page : '']));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { flash('error', 'Your session expired. Please try again.'); redirect($back); }
    $act = $_POST['action'] ?? '';
    $uid = (int)($_POST['user_id'] ?? 0);
    $reason = mb_substr(trim($_POST['reason'] ?? ''), 0, 255);
    $s = $pdo->prepare('SELECT id, role, status, full_name FROM users WHERE id = ?');
    $s->execute([$uid]);
    $u = $s->fetch();

    if (!$u || $u['role'] === 'admin') {
        flash('error', 'Admin accounts cannot be changed from this page.');
    } elseif ($act === 'suspend' && $u['status'] !== 'suspended') {
        if (mb_strlen($reason) < 10) { flash('error', 'Write a reason of at least 10 characters.'); redirect($back); }
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE users SET status = 'suspended' WHERE id = ? AND role <> 'admin'")->execute([$uid]);
        if ($u['role'] === 'employer') {
            $pdo->prepare("UPDATE employer_profiles SET verification_status = 'suspended', rejection_reason = ?, peso_endorsed = 0 WHERE user_id = ?")->execute([$reason, $uid]);
            $pdo->prepare("UPDATE job_posts SET status = 'closed' WHERE employer_id = ? AND status IN ('approved','pending')")->execute([$uid]);
        }
        log_admin('user_suspended', 'user', $uid, $reason);
        $pdo->commit();
        flash('success', $u['full_name'] . ' was suspended.' . ($u['role'] === 'employer' ? ' Their jobs were closed.' : ''));
    } elseif ($act === 'warn' && $u['role'] === 'seeker' && $u['status'] !== 'suspended') {
        if (mb_strlen($reason) < 10) { flash('error', 'Write a reason of at least 10 characters.'); redirect($back); }
        $pdo->beginTransaction();
        warn_user($pdo, $uid, $reason, 'seeker/notifications.php');
        $pdo->commit();
        flash('success', $u['full_name'] . ' was warned. They were notified.');
    } elseif ($act === 'reactivate' && $u['status'] === 'suspended') {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ? AND role <> 'admin'")->execute([$uid]);
        if ($u['role'] === 'employer') $pdo->prepare("UPDATE employer_profiles SET verification_status = 'pending', rejection_reason = NULL WHERE user_id = ? AND verification_status = 'suspended'")->execute([$uid]);
        log_admin('user_reactivated', 'user', $uid, $u['role'] === 'employer' ? 'Returned to pending review' : null);
        $pdo->commit();
        flash('success', $u['full_name'] . ' was reactivated.' . ($u['role'] === 'employer' ? ' The employer must be verified again.' : ''));
    }
    redirect($back);
}

$cond = ['all' => '1=1', 'seeker' => "u.role = 'seeker'", 'employer' => "u.role = 'employer'", 'suspended' => "u.status = 'suspended'", 'admin' => "u.role = 'admin'"];
$where = ' WHERE ' . $cond[$tab]; $p = [];
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where .= ' AND (u.full_name ILIKE :q1 OR u.email ILIKE :q2)'; $p = ['q1' => $like, 'q2' => $like];
}
$c = $pdo->prepare('SELECT COUNT(*) FROM users u' . $where); $c->execute($p);
$total = (int)$c->fetchColumn();
$r = $pdo->prepare('SELECT u.id, u.role, u.full_name, u.email, u.status, u.created_at, u.last_login FROM users u' . $where . ' ORDER BY u.created_at DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
$r->execute($p);
$rows = $r->fetchAll();
$warned = [];
if ($rows) {
    $wids = implode(',', array_map('intval', array_column($rows, 'id')));
    foreach ($pdo->query("SELECT target_id, COUNT(*) AS c FROM admin_activity_logs WHERE action = 'user_warned' AND target_type = 'user' AND target_id IN ($wids) GROUP BY target_id") as $w) $warned[(int)$w['target_id']] = (int)$w['c'];
}
$tabCount = [];
foreach ($cond as $k => $w) $tabCount[$k] = (int)$pdo->query("SELECT COUNT(*) FROM users u WHERE $w")->fetchColumn();
$base = url('admin/users.php?' . http_build_query(array_filter(['tab' => $tab !== 'all' ? $tab : '', 'q' => $q])));
$roleLabel = ['seeker' => 'Job seeker', 'employer' => 'Employer', 'admin' => 'Admin'];
$stCls = ['active' => 'st-ok', 'pending_email' => 'st-pending', 'suspended' => 'st-bad'];
$stLbl = ['active' => 'Active', 'pending_email' => 'Email not verified', 'suspended' => 'Suspended'];

$tabDot = ['all' => '#9AA9A0', 'seeker' => '#2FB463', 'employer' => '#F2B705', 'suspended' => '#D64545', 'admin' => '#0F3D2E'];
$roleIcon = ['seeker' => 'user', 'employer' => 'building', 'admin' => 'shield'];
$umSt = ['active' => 'ok', 'pending_email' => 'wait', 'suspended' => 'bad'];
// Friendly "x days ago" text. Returns [text, isToday].
$rel = function (?string $ts): array {
    if (!$ts) return ['Never', false];
    $d = (int)round((strtotime('today') - strtotime(date('Y-m-d', strtotime($ts)))) / 86400);
    if ($d <= 0) return ['Today', true];
    if ($d === 1) return ['Yesterday', false];
    if ($d < 30) return [$d . ' days ago', false];
    if ($d < 365) { $m = intdiv($d, 30); return [$m . ($m === 1 ? ' month' : ' months') . ' ago', false]; }
    $y = intdiv($d, 365); return [$y . ($y === 1 ? ' year' : ' years') . ' ago', false];
};

// ---------- Export: Print, Save as PDF, Save as CSV (every row for the current tab and search) ----------
require_once __DIR__ . '/../includes/admin_export.php';
$xr = $pdo->prepare('SELECT u.role, u.full_name, u.email, u.status, u.created_at, u.last_login FROM users u' . $where . ' ORDER BY u.created_at DESC LIMIT ' . XP_MAX_ROWS);
$xr->execute($p);
$xpRows = []; $xn = 0;
foreach ($xr as $x) $xpRows[] = [++$xn, $x['full_name'], $x['email'], $roleLabel[$x['role']] ?? $x['role'], $stLbl[$x['status']] ?? $x['status'],
    date('M j, Y', strtotime($x['created_at'])), $x['last_login'] ? date('M j, Y g:i A', strtotime($x['last_login'])) : 'Never'];
$xp = ['title' => 'Users Report', 'slug' => 'users',
    'purpose' => 'List of registered accounts (job seekers, employers and administrators) with their status and activity dates.',
    'filters' => ['Group' => $tabs[$tab], 'Search' => $q],
    'head' => ['No.', 'Name', 'Email', 'Role', 'Status', 'Date joined', 'Last login'], 'rows' => $xpRows];

$pageTitle = 'Users';
require __DIR__ . '/../includes/admin_top.php';
?>
<link rel="stylesheet" href="<?= e(url('assets/css/admin-users.css')) ?>">
<div class="um-page">
<div class="um-hero">
  <div><h1>Users</h1><p>Search accounts, warn or suspend misuse, and reactivate when it is resolved. Admin accounts cannot be created or changed here.</p></div>
  <div class="um-hero-chips">
    <span class="um-chip um-chip-gold"><?= icon('users', 16) ?><?= number_format($tabCount['all']) ?> <?= $tabCount['all'] === 1 ? 'account' : 'accounts' ?></span>
    <?php if ($tabCount['suspended'] > 0): ?><span class="um-chip um-chip-glass"><?= number_format($tabCount['suspended']) ?> suspended</span><?php endif; ?>
  </div>
</div>


<nav class="um-tabs" aria-label="Filter users"><?php foreach ($tabs as $k => $l): ?><a class="<?= $tab === $k ? 'on' : '' ?>" href="<?= url('admin/users.php?tab=' . $k) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><i class="um-dot" style="--c:<?= $tabDot[$k] ?>"></i><?= e($l) ?><span class="um-badge"><?= $tabCount[$k] ?></span></a><?php endforeach; ?></nav>

<form method="get" class="um-searchbar form"><input type="hidden" name="tab" value="<?= e($tab) ?>">
  <div class="um-field"><?= icon('search', 16) ?><input name="q" type="search" maxlength="100" placeholder="Search name or email" value="<?= e($q) ?>" aria-label="Search"></div>
  <button class="btn btn-primary btn-sm" type="submit">Search</button><?php if ($q !== ''): ?><a class="btn btn-outline btn-sm" href="<?= url('admin/users.php?tab=' . $tab) ?>">Clear</a><?php endif; ?></form>
<p class="um-results">Showing <b><?= number_format($total) ?></b> <?= $total === 1 ? 'user' : 'users' ?></p>

<?php if (!$rows): ?><div class="um-card um-empty"><div class="um-empty-ico"><?= icon('users', 26) ?></div><h3>No users found</h3><p>Try a different tab or search.</p></div>
<?php else: ?>
  <div class="um-card"><table class="um-tbl">
    <thead><tr><th>User</th><th>Role</th><th>Status</th><th>Joined</th><th>Last login</th><th>Action</th></tr></thead><tbody>
    <?php foreach ($rows as $x): [$jr] = $rel($x['created_at']); [$lr, $lNow] = $rel($x['last_login']); ?>
      <tr>
        <td><div class="um-user"><span class="um-av um-av-<?= e($x['role']) ?><?= $x['status'] === 'suspended' ? ' is-suspended' : '' ?>" aria-hidden="true"><?= e(initials($x['full_name'])) ?></span>
          <div class="um-who"><b><?= e($x['full_name']) ?></b><div class="small"><?= e($x['email']) ?></div><?php if (!empty($warned[(int)$x['id']])): ?><span class="st st-pending" title="Warnings sent by the admin team">Warned x<?= (int)$warned[(int)$x['id']] ?></span><?php endif; ?></div></div></td>
        <td><span class="um-role um-role-<?= e($x['role']) ?>"><?= icon($roleIcon[$x['role']] ?? 'user', 14) ?><?= e($roleLabel[$x['role']] ?? $x['role']) ?></span></td>
        <td><span class="um-status um-st-<?= $umSt[$x['status']] ?? 'off' ?>"><?= e($stLbl[$x['status']] ?? $x['status']) ?></span></td>
        <td><span class="um-date"><?= e(date('M j, Y', strtotime($x['created_at']))) ?></span><span class="um-rel"><?= e($jr) ?></span></td>
        <td><?php if ($x['last_login']): ?><span class="um-date"><?= e(date('M j, Y', strtotime($x['last_login']))) ?></span><span class="um-rel<?= $lNow ? ' now' : '' ?>"><?= e($lr) ?></span><?php else: ?><span class="um-never">Never</span><?php endif; ?></td>
        <td>
          <?php if ($x['role'] === 'admin'): ?>
            <div class="um-actions"><span class="um-locked"><?= icon('lock', 14) ?>Protected</span></div>
          <?php else: ?>
            <div class="um-actions">
            <?php if ($x['role'] === 'employer'): ?><a class="btn btn-outline btn-sm" href="<?= url('admin/verify-review.php?id=' . (int)$x['id']) ?>">Profile <?= icon('arrow', 14) ?></a><?php endif; ?>
            <?php if ($x['status'] === 'suspended'): ?>
              <form method="post" action="<?= e(url($back)) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int)$x['id'] ?>">
                <button class="btn btn-primary btn-sm" name="action" value="reactivate" data-confirm="This account will be reactivated." data-confirm-title="Reactivate account?" data-confirm-ok="Reactivate" data-confirm-tone="info">Reactivate</button></form>
            <?php else: ?>
              <?php if ($x['role'] === 'seeker'): ?>
              <details class="pop"><summary class="btn btn-gold btn-sm">Warn</summary>
                <form method="post" action="<?= e(url($back)) ?>" class="form pop-body"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int)$x['id'] ?>">
                  <p class="um-pop-title">Warn <?= e($x['full_name']) ?>?</p>
                  <p class="um-pop-hint">They stay active and get a notification with your reason. The warning is saved in the activity log.</p>
                  <textarea name="reason" rows="2" maxlength="220" placeholder="Reason (required, at least 10 characters, they will see it)" required></textarea>
                  <div class="um-pop-btns"><button class="btn btn-outline btn-sm" type="button" data-um-close>Cancel</button>
                    <button class="btn btn-gold btn-sm" name="action" value="warn" data-confirm="They will be notified of this warning." data-confirm-title="Send warning?" data-confirm-ok="Send warning" data-confirm-tone="info">Send warning</button></div></form></details>
              <?php endif; ?>
              <details class="pop"><summary class="btn btn-danger btn-sm">Suspend</summary>
                <form method="post" action="<?= e(url($back)) ?>" class="form pop-body"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int)$x['id'] ?>">
                  <p class="um-pop-title">Suspend <?= e($x['full_name']) ?>?</p>
                  <p class="um-pop-hint"><?= $x['role'] === 'employer' ? 'They will be locked out and their open jobs will be closed.' : 'They will be locked out until you reactivate them.' ?></p>
                  <textarea name="reason" rows="2" maxlength="255" placeholder="Reason (required, at least 10 characters)" required></textarea>
                  <div class="um-pop-btns"><button class="btn btn-outline btn-sm" type="button" data-um-close>Cancel</button>
                    <button class="btn btn-danger btn-sm" name="action" value="suspend" data-confirm="This account will be suspended." data-confirm-title="Suspend account?" data-confirm-ok="Suspend" data-confirm-tone="danger">Confirm suspend</button></div></form></details>
            <?php endif; ?>
            </div>
          <?php endif; ?>
        </td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?= pager($total, $page, $per, $base) ?>
<?php endif; ?>
</div>
<script>
(function () {
  var pops = document.querySelectorAll('.um-page details.pop');
  if (!pops.length) return;
  function closeAll() { pops.forEach(function (p) { p.open = false; }); }
  pops.forEach(function (p) { p.addEventListener('toggle', function () { var td = p.closest('td'); if (td) td.classList.toggle('um-open', p.open); }); });
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (t.closest && t.closest('[data-um-close]')) { closeAll(); return; }
    pops.forEach(function (p) { if (p.open && (t === p || !p.contains(t))) p.open = false; });
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAll(); });
})();
</script>
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>
