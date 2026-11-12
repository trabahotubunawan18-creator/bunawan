<?php
require __DIR__ . '/../includes/admin_auth.php';
require_admin();
$pdo = db();

$tabs = ['pending' => 'Pending', 'more_info' => 'More info', 'verified' => 'Verified', 'rejected' => 'Rejected', 'suspended' => 'Suspended', 'all' => 'All'];
$tab = array_key_exists($_GET['tab'] ?? '', $tabs) ? $_GET['tab'] : 'pending';
$q = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$page = max(1, (int)($_GET['page'] ?? 1)); $per = 15;

$where = ' WHERE 1=1'; $p = [];
if ($tab === 'verified') $where .= " AND ep.verification_status IN ('verified_business','verified_local')";
elseif ($tab !== 'all') { $where .= ' AND ep.verification_status = :st'; $p['st'] = $tab; }
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where .= ' AND (ep.employer_name ILIKE :q1 OR u.email ILIKE :q2 OR ep.contact_person ILIKE :q3)';
    $p += ['q1' => $like, 'q2' => $like, 'q3' => $like];
}
$from = ' FROM employer_profiles ep JOIN users u ON u.id = ep.user_id JOIN barangays b ON b.id = ep.barangay_id';
$c = $pdo->prepare('SELECT COUNT(*)' . $from . $where); $c->execute($p);
$total = (int)$c->fetchColumn();
$order = in_array($tab, ['pending', 'more_info'], true) ? 'ep.created_at ASC' : 'ep.created_at DESC';
$r = $pdo->prepare('SELECT ep.user_id, ep.logo_path, ep.employer_name, ep.employer_type, ep.verification_status, ep.created_at, ep.permit_expiry, u.email, b.name AS barangay'
    . $from . $where . ' ORDER BY ' . $order . ' LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
$r->execute($p);
$rows = $r->fetchAll();

$counts = [];
foreach ($pdo->query('SELECT verification_status s, COUNT(*) c FROM employer_profiles GROUP BY s') as $x) $counts[$x['s']] = (int)$x['c'];
$tabCount = ['pending' => $counts['pending'] ?? 0, 'more_info' => $counts['more_info'] ?? 0,
    'verified' => ($counts['verified_business'] ?? 0) + ($counts['verified_local'] ?? 0), 'rejected' => $counts['rejected'] ?? 0,
    'suspended' => $counts['suspended'] ?? 0, 'all' => array_sum($counts)];
$base = url('admin/verification.php?' . http_build_query(array_filter(['tab' => $tab, 'q' => $q])));

// ---------- Export: Print, Save as PDF, Save as CSV (every row for the current tab and search) ----------
require_once __DIR__ . '/../includes/admin_export.php';
$xr = $pdo->prepare('SELECT ep.employer_name, ep.employer_type, ep.verification_status, ep.created_at, ep.permit_expiry, u.email, b.name AS barangay'
    . $from . $where . ' ORDER BY ' . $order . ' LIMIT ' . XP_MAX_ROWS);
$xr->execute($p);
$xpType = ['registered_business' => 'Business', 'organization' => 'Organization', 'individual' => 'Individual'];
$xpRows = []; $xn = 0;
foreach ($xr as $x) $xpRows[] = [++$xn, $x['employer_name'], $xpType[$x['employer_type']] ?? $x['employer_type'], $x['barangay'], $x['email'],
    verif_info($x['verification_status'])[0], date('M j, Y', strtotime($x['created_at'])), $x['permit_expiry'] ? date('M j, Y', strtotime($x['permit_expiry'])) : 'N/A'];
$xp = ['title' => 'Employer Verification Report', 'slug' => 'employer-verification',
    'purpose' => 'List of employers and the result of their verification review, with registration and permit expiry dates.',
    'filters' => ['Status' => $tabs[$tab], 'Search' => $q],
    'head' => ['No.', 'Employer', 'Type', 'Barangay', 'Email', 'Verification status', 'Date registered', 'Permit expiry'],
    'rows' => $xpRows, 'landscape' => true];

$pageTitle = 'Employer verification';
require __DIR__ . '/../includes/admin_top.php';
$typeLabels = ['registered_business' => 'Business', 'organization' => 'Organization', 'individual' => 'Individual'];
$waiting = $tabCount['pending'];
$tabDot = ['pending' => 'd-gold', 'more_info' => 'd-amber', 'verified' => 'd-green', 'rejected' => 'd-rose', 'suspended' => 'd-rose', 'all' => 'd-grey'];
$tabLink = fn(string $k) => url('admin/verification.php?' . http_build_query(array_filter(['tab' => $k, 'q' => $q])));
?>
<div class="ap">
  <section class="ap-hero">
    <div>
      <h1>Employer verification</h1>
      <p>Check every employer before they can post jobs. Oldest requests come first.</p>
    </div>
    <?php if ($waiting > 0): ?>
      <span class="ap-pill"><?= icon('shield', 15) ?> <?= $waiting ?> waiting for review</span>
    <?php else: ?>
      <span class="ap-pill ok"><?= icon('check', 15) ?> All caught up</span>
    <?php endif; ?>
  </section>


  <div class="ap-tabs" role="tablist" aria-label="Verification status">
    <?php foreach ($tabs as $k => $label): ?>
      <a class="<?= $tab === $k ? 'on' : '' ?>" href="<?= $tabLink($k) ?>" <?= $tab === $k ? 'aria-current="page"' : '' ?>><i class="dot <?= $tabDot[$k] ?>"></i><?= e($label) ?><em><?= $tabCount[$k] ?></em></a>
    <?php endforeach; ?>
  </div>

  <form method="get" class="ap-search">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <?= icon('search', 18) ?>
    <input name="q" type="search" maxlength="100" placeholder="Search employer name, email, or contact person" value="<?= e($q) ?>" aria-label="Search">
    <button class="btn btn-primary btn-sm" type="submit">Search</button>
    <?php if ($q !== ''): ?><a class="btn btn-outline btn-sm" href="<?= url('admin/verification.php?tab=' . $tab) ?>">Clear</a><?php endif; ?>
  </form>

  <?php if (!$rows): ?>
    <div class="ap-empty">
      <span class="ring"><?= icon($q !== '' ? 'search' : 'shield', 30) ?></span>
      <h3><?= $q !== '' ? 'No matches found' : 'Nothing here' ?></h3>
      <p><?= $q !== '' ? 'No employers match &ldquo;' . e($q) . '&rdquo; in this view. Try another spelling or a different tab.' : 'No employers match this view.' ?></p>
      <?php if ($tab !== 'all' || $q !== ''): ?><a class="btn btn-outline btn-sm" href="<?= url('admin/verification.php?tab=all') ?>">View all employers</a><?php endif; ?>
    </div>
  <?php else: ?>
    <div class="ap-card">
      <div class="tbl-wrap"><table class="ap-tbl">
        <thead><tr><th>Employer</th><th>Type</th><th>Barangay</th><th>Registered</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $e1): $sl = verif_info($e1['verification_status'])[0];
          $stCls = ['pending' => 'st-pending', 'more_info' => 'st-pending', 'verified_business' => 'st-ok', 'verified_local' => 'st-ok', 'rejected' => 'st-bad', 'suspended' => 'st-bad'][$e1['verification_status']];
          $isWait = in_array($e1['verification_status'], ['pending', 'more_info'], true);
          $wd = (int)floor((time() - strtotime($e1['created_at'])) / 86400);
          $permitSoon = $e1['verification_status'] === 'verified_business' && $e1['permit_expiry'] && $e1['permit_expiry'] <= date('Y-m-d', strtotime('+30 days')); ?>
          <tr>
            <td><div class="ap-emp"><span class="ap-av"<?= !empty($e1['logo_path']) ? ' style="overflow:hidden;padding:0;background:#fff;border:1px solid var(--line)"' : '' ?>><?php if (!empty($e1['logo_path'])): ?><img src="<?= e(public_file_url($e1['logo_path'])) ?>" alt="" style="width:100%;height:100%;object-fit:cover;display:block"><?php else: ?><?= e(initials($e1['employer_name'])) ?><?php endif; ?></span><div><b><?= e($e1['employer_name']) ?></b><span><?= e($e1['email']) ?></span></div></div></td>
            <td><span class="ap-chip"><?= e($typeLabels[$e1['employer_type']] ?? 'Employer') ?></span></td>
            <td><span class="ap-loc"><?= icon('pin', 14) ?> <?= e($e1['barangay']) ?></span></td>
            <td><?= e(date('M j, Y', strtotime($e1['created_at']))) ?>
              <?php if ($isWait): ?><div class="ap-wait<?= $wd >= 3 ? ' late' : '' ?>"><?= icon('clock', 12) ?> <?= $wd < 1 ? 'Waiting today' : 'Waiting ' . $wd . ' day' . ($wd > 1 ? 's' : '') ?></div><?php endif; ?></td>
            <td><span class="st <?= $stCls ?>"><?= e($sl) ?></span>
              <?php if ($permitSoon): ?><div class="ap-wait late"><?= icon('clock', 12) ?> Permit <?= $e1['permit_expiry'] < date('Y-m-d') ? 'expired' : 'expires ' . e(date('M j', strtotime($e1['permit_expiry']))) ?></div><?php endif; ?></td>
            <td class="ap-act"><a class="btn btn-primary btn-sm" href="<?= url('admin/verify-review.php?id=' . (int)$e1['user_id']) ?>">Review <?= icon('arrow', 14) ?></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>
    <?= pager($total, $page, $per, $base) ?>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>
