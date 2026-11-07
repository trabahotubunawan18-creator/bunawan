<?php
require __DIR__ . '/../includes/admin_auth.php';
require_admin();
$pdo = db();

$q = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '';
$to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '';
$page = max(1, (int)($_GET['page'] ?? 1)); $per = 25;

$where = ' WHERE 1=1'; $p = [];
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where .= ' AND (l.action ILIKE :q1 OR l.reason ILIKE :q2 OR l.target_type ILIKE :q3 OR u.full_name ILIKE :q4)';
    $p += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like];
}
if ($from) { $where .= ' AND l.created_at >= :f'; $p['f'] = $from . ' 00:00:00'; }
if ($to)   { $where .= ' AND l.created_at <= :t'; $p['t'] = $to . ' 23:59:59'; }
$fromSql = ' FROM admin_activity_logs l JOIN users u ON u.id = l.admin_id' . $where;

// ---------- Export: Print, Save as PDF, Save as CSV (same search and dates as the list) ----------
require_once __DIR__ . '/../includes/admin_export.php';
$xr = $pdo->prepare('SELECT l.created_at, u.full_name, l.action, l.target_type, l.target_id, l.reason, l.ip_address' . $fromSql . ' ORDER BY l.id DESC LIMIT ' . XP_MAX_ROWS);
$xr->execute($p);
$xpRows = []; $xn = 0;
foreach ($xr as $x) $xpRows[] = [++$xn, date('M j, Y g:i A', strtotime($x['created_at'])), $x['full_name'], action_label($x['action']),
    $x['target_type'] ? $x['target_type'] . ' #' . (int)$x['target_id'] : 'N/A', ($x['reason'] ?? '') !== '' ? $x['reason'] : 'N/A', $x['ip_address'] ?? 'N/A'];
$xp = ['title' => 'Admin Activity Log', 'slug' => 'activity-log', 'log' => 'activity_log_exported',
    'purpose' => 'Permanent record of important actions taken by administrators, newest first.',
    'filters' => ['Search' => $q, 'From' => $from ? date('M j, Y', strtotime($from)) : '', 'To' => $to ? date('M j, Y', strtotime($to)) : ''],
    'head' => ['No.', 'Date and time', 'Admin', 'Action', 'Target', 'Reason', 'IP address'],
    'rows' => $xpRows, 'landscape' => true];

$c = $pdo->prepare('SELECT COUNT(*)' . $fromSql); $c->execute($p);
$total = (int)$c->fetchColumn();
$r = $pdo->prepare('SELECT l.*, u.full_name' . $fromSql . ' ORDER BY l.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
$r->execute($p);
$rows = $r->fetchAll();
$qs = array_filter(['q' => $q, 'from' => $from, 'to' => $to]);

$today = (int)$pdo->query('SELECT COUNT(*) FROM admin_activity_logs WHERE created_at >= CURRENT_DATE')->fetchColumn();
// Visual style and icon for each kind of action (display only)
$actionLook = function (string $a): array {
    $tone = 'st-off';
    if (preg_match('/remov|suspend|declin|reject|deactiv|cancel|delet|dismiss/', $a)) $tone = 'st-bad';
    elseif (preg_match('/warn|more_info|flag/', $a)) $tone = 'st-pending';
    elseif (preg_match('/approv|activat|add|verif|reactiv|replied|publish|enabled/', $a)) $tone = 'st-ok';
    $ic = 'clock';
    foreach (['login' => 'lock', 'logout' => 'logout', 'premium' => 'star', 'categor' => 'tag', 'job' => 'briefcase', 'employer' => 'shield', 'verif' => 'shield',
              'user' => 'users', 'message' => 'mail', 'export' => 'file', 'setting' => 'cog', 'barangay' => 'map', 'complaint' => 'flag', 'report' => 'flag'] as $k => $v) {
        if (strpos($a, $k) !== false) { $ic = $v; break; }
    }
    return [$tone, $ic];
};

$pageTitle = 'Activity log';
require __DIR__ . '/../includes/admin_top.php';
?>
<header class="ad-hero">
  <div class="ad-hero-main"><h1 class="dash-title">Activity log</h1>
  <p class="dash-sub">A permanent record of important admin actions. It is read-only.</p></div>
  <div class="ad-chips"><span class="ad-chip ad-chip--gold"><?= icon('clock', 16) ?> <?= number_format($total) ?> <?= $total === 1 ? 'entry' : 'entries' ?></span><span class="ad-chip"><?= number_format($today) ?> today</span></div>
</header>


<form method="get" class="al-filter form">
  <div class="al-field al-search"><label for="alq">Search</label>
    <div class="al-input"><?= icon('search', 17) ?><input id="alq" name="q" type="search" maxlength="100" placeholder="Search action, reason, or admin" value="<?= e($q) ?>"></div></div>
  <div class="al-field"><label for="alf">From</label><input id="alf" type="date" name="from" value="<?= e($from) ?>"></div>
  <div class="al-field"><label for="alt">To</label><input id="alt" type="date" name="to" value="<?= e($to) ?>"></div>
  <div class="al-btns">
    <button class="btn btn-primary btn-sm" type="submit">Filter</button>
    <a class="btn btn-outline btn-sm" href="<?= url('admin/activity-log.php') ?>">Clear</a>
  </div>
</form>

<p class="ad-listhead"><span><b><?= number_format($total) ?></b> <?= $total === 1 ? 'entry' : 'entries' ?><?= $qs ? ' match your filters' : '' ?></span><span>Newest first</span></p>
<?php if (!$rows): ?>
  <div class="card empty ad-empty"><div class="ad-empty-ico"><?= icon('clock', 28) ?></div><h3>No entries</h3><p>Admin actions will appear here.</p></div>
<?php else: ?>
  <div class="card ad-tablecard"><div class="tbl-wrap"><table class="tbl al-table">
    <thead><tr><th>Time</th><th>Admin</th><th>Action</th><th>Target</th><th>Reason</th></tr></thead><tbody>
    <?php foreach ($rows as $x): [$tone, $ic] = $actionLook($x['action']); $ts = strtotime($x['created_at']); ?>
      <tr><td class="al-time"><b><?= e(date('M j, Y', $ts)) ?></b><span><?= e(date('g:i A', $ts)) ?></span></td>
        <td><span class="al-admin"><span class="al-av" aria-hidden="true"><?= e(initials($x['full_name'])) ?></span><?= e($x['full_name']) ?></span></td>
        <td><span class="al-action"><span class="al-ic al-<?= $tone ?>"><?= icon($ic, 16) ?></span><span class="st <?= $tone ?>"><?= e(action_label($x['action'])) ?></span></span></td>
        <td><?= $x['target_type'] ? '<span class="al-target">' . e($x['target_type']) . ' <b>#' . (int)$x['target_id'] . '</b></span>' : '<span class="al-none">&mdash;</span>' ?></td>
        <td class="al-reason"><?= ($x['reason'] ?? '') !== '' ? e($x['reason']) : '<span class="al-none">&mdash;</span>' ?></td></tr>
    <?php endforeach; ?></tbody></table></div></div>
  <?= pager($total, $page, $per, url('admin/activity-log.php' . ($qs ? '?' . http_build_query($qs) : ''))) ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>