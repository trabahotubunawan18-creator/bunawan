<?php
require __DIR__ . '/../includes/admin_auth.php';
require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check() && ($_POST['action'] ?? '') === 'archive_expired') {
    $u = $pdo->prepare("UPDATE job_posts SET status = 'expired' WHERE status = 'approved' AND deadline < CURRENT_DATE");
    $u->execute();
    log_admin('expired_jobs_archived', 'job', null, $u->rowCount() . ' jobs');
    flash('success', $u->rowCount() . ' expired job' . ($u->rowCount() === 1 ? ' was' : 's were') . ' archived.');
    redirect('admin/jobs.php?tab=expired');
}

$tabs = ['all' => 'All', 'active' => 'Active', 'pending' => 'Pending', 'rejected' => 'Rejected', 'closed' => 'Closed', 'expired' => 'Expired', 'removed' => 'Removed'];
$tab = array_key_exists($_GET['tab'] ?? '', $tabs) ? $_GET['tab'] : 'all';
$q = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$page = max(1, (int)($_GET['page'] ?? 1)); $per = 15;
$cond = ['all' => '1=1', 'active' => "j.status = 'approved' AND j.deadline >= CURRENT_DATE", 'pending' => "j.status = 'pending'", 'rejected' => "j.status = 'rejected'",
         'closed' => "j.status = 'closed'", 'expired' => "((j.status = 'approved' AND j.deadline < CURRENT_DATE) OR j.status = 'expired')", 'removed' => "j.status = 'removed'"];
$where = ' WHERE ' . $cond[$tab]; $p = [];
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where .= ' AND (j.title ILIKE :q1 OR ep.employer_name ILIKE :q2)'; $p = ['q1' => $like, 'q2' => $like];
}
$from = ' FROM job_posts j JOIN employer_profiles ep ON ep.user_id = j.employer_id JOIN barangays b ON b.id = j.barangay_id';
$c = $pdo->prepare('SELECT COUNT(*)' . $from . $where); $c->execute($p);
$total = (int)$c->fetchColumn();
$r = $pdo->prepare('SELECT j.id, j.title, j.status, j.deadline, j.created_at, j.views, ep.employer_name, b.name AS barangay,
        (SELECT COUNT(*) FROM job_applications a WHERE a.job_id = j.id) AS apps' . $from . $where . ' ORDER BY j.created_at DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
$r->execute($p);
$rows = $r->fetchAll();
$staleCount = (int)$pdo->query("SELECT COUNT(*) FROM job_posts WHERE status = 'approved' AND deadline < CURRENT_DATE")->fetchColumn();
// Counts for the tabs
$tc = $pdo->query("SELECT COUNT(*) a,
    COALESCE(SUM(CASE WHEN j.status = 'approved' AND j.deadline >= CURRENT_DATE THEN 1 ELSE 0 END), 0) act,
    COALESCE(SUM(CASE WHEN j.status = 'pending' THEN 1 ELSE 0 END), 0) pen,
    COALESCE(SUM(CASE WHEN j.status = 'rejected' THEN 1 ELSE 0 END), 0) rej,
    COALESCE(SUM(CASE WHEN j.status = 'closed' THEN 1 ELSE 0 END), 0) clo,
    COALESCE(SUM(CASE WHEN (j.status = 'approved' AND j.deadline < CURRENT_DATE) OR j.status = 'expired' THEN 1 ELSE 0 END), 0) expd,
    COALESCE(SUM(CASE WHEN j.status = 'removed' THEN 1 ELSE 0 END), 0) rem" . $from)->fetch();
$tabCount = ['all' => (int)$tc['a'], 'active' => (int)$tc['act'], 'pending' => (int)$tc['pen'], 'rejected' => (int)$tc['rej'],
             'closed' => (int)$tc['clo'], 'expired' => (int)$tc['expd'], 'removed' => (int)$tc['rem']];
$tabDot = ['all' => 'd-grey', 'active' => 'd-green', 'pending' => 'd-gold', 'rejected' => 'd-rose', 'closed' => 'd-grey', 'expired' => 'd-amber', 'removed' => 'd-rose'];
$tabLink = fn(string $k) => url('admin/jobs.php?' . http_build_query(array_filter(['tab' => $k !== 'all' ? $k : '', 'q' => $q])));
$base = url('admin/jobs.php?' . http_build_query(array_filter(['tab' => $tab !== 'all' ? $tab : '', 'q' => $q])));

// ---------- Export: Print, Save as PDF, Save as CSV (every row for the current tab and search) ----------
require_once __DIR__ . '/../includes/admin_export.php';
$xr = $pdo->prepare('SELECT j.title, j.status, j.deadline, j.created_at, j.views, ep.employer_name, b.name AS barangay,
        (SELECT COUNT(*) FROM job_applications a WHERE a.job_id = j.id) AS apps' . $from . $where . ' ORDER BY j.created_at DESC LIMIT ' . XP_MAX_ROWS);
$xr->execute($p);
$xpRows = []; $xn = 0; $xViews = 0; $xApps = 0;
foreach ($xr as $x) {
    $stText = job_status($x['status'])[0];
    if ($x['status'] === 'approved' && $x['deadline'] < date('Y-m-d')) $stText = 'Expired (past deadline)';
    $xpRows[] = [++$xn, $x['title'], $x['employer_name'], $x['barangay'], $stText, date('M j, Y', strtotime($x['created_at'])),
        date('M j, Y', strtotime($x['deadline'])), (int)$x['views'], (int)$x['apps']];
    $xViews += (int)$x['views']; $xApps += (int)$x['apps'];
}
$xp = ['title' => 'Job Posts Report', 'slug' => 'jobs',
    'purpose' => 'List of job posts on the site with their employer, barangay, status, deadline, views and number of applicants.',
    'filters' => ['Status' => $tabs[$tab], 'Search' => $q],
    'head' => ['No.', 'Job title', 'Employer', 'Barangay', 'Status', 'Date posted', 'Deadline', 'Views', 'Applicants'],
    'rows' => $xpRows, 'right' => [7, 8], 'landscape' => true,
    'total' => ['', 'Total', '', '', '', '', '', $xViews, $xApps]];

$pageTitle = 'Jobs';
require __DIR__ . '/../includes/admin_top.php';
?>
<div class="ap">
  <section class="ap-hero">
    <div>
      <h1>Jobs</h1>
      <p>A list of every job on the site. Clean posts publish automatically, so you only need to open a job to close or remove it. Held posts are in <a href="<?= url('admin/flagged.php') ?>">Flagged posts</a>.</p>
    </div>
    <div class="ap-hero-act">
      <span class="ap-pill"><?= icon('briefcase', 15) ?> <?= number_format($tabCount['active']) ?> active job<?= $tabCount['active'] === 1 ? '' : 's' ?></span>
      <?php if ($staleCount > 0): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="archive_expired">
          <button class="btn btn-white btn-sm" type="submit" data-confirm="All jobs past their deadline will be archived." data-confirm-title="Archive expired jobs?" data-confirm-ok="Archive" data-confirm-tone="info"><?= icon('clock', 15) ?> Archive <?= $staleCount ?> expired</button></form>
      <?php endif; ?>
    </div>
  </section>


  <div class="ap-tabs" aria-label="Job status">
    <?php foreach ($tabs as $k => $l): ?>
      <a class="<?= $tab === $k ? 'on' : '' ?>" href="<?= $tabLink($k) ?>" <?= $tab === $k ? 'aria-current="page"' : '' ?>><i class="dot <?= $tabDot[$k] ?>"></i><?= e($l) ?><em><?= $tabCount[$k] ?></em></a>
    <?php endforeach; ?>
  </div>

  <form method="get" class="ap-search"><input type="hidden" name="tab" value="<?= e($tab) ?>">
    <?= icon('search', 18) ?>
    <input name="q" type="search" maxlength="100" placeholder="Search job title or employer" value="<?= e($q) ?>" aria-label="Search">
    <button class="btn btn-primary btn-sm" type="submit">Search</button>
    <?php if ($q !== ''): ?><a class="btn btn-outline btn-sm" href="<?= url('admin/jobs.php?tab=' . $tab) ?>">Clear</a><?php endif; ?>
  </form>

  <p class="ap-count">Showing <b><?= number_format($total) ?></b> <?= $total === 1 ? 'job' : 'jobs' ?><?= $q !== '' ? ' for &ldquo;' . e($q) . '&rdquo;' : '' ?></p>

  <?php if (!$rows): ?>
    <div class="ap-empty">
      <span class="ring"><?= icon($q !== '' ? 'search' : 'briefcase', 30) ?></span>
      <h3>No jobs found</h3>
      <p>Try a different tab or search.</p>
      <?php if ($tab !== 'all' || $q !== ''): ?><a class="btn btn-outline btn-sm" href="<?= url('admin/jobs.php') ?>">Show all jobs</a><?php endif; ?>
    </div>
  <?php else: ?>
    <div class="ap-card">
      <div class="tbl-wrap"><table class="ap-tbl">
        <thead><tr><th>Job</th><th>Employer</th><th>Status</th><th>Deadline</th><th>Views</th><th>Applicants</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $x): $exp = $x['status'] === 'approved' && $x['deadline'] < date('Y-m-d'); [$jl, $jc] = job_status($exp ? 'expired' : $x['status']);
          $live = $x['status'] === 'approved' && !$exp; $dl = days_left($x['deadline']); ?>
          <tr>
            <td><div class="ap-emp"><span class="ap-av ap-job"><?= icon('briefcase', 18) ?></span><div><b><?= e($x['title']) ?></b><span><?= icon('pin', 12) ?> <?= e($x['barangay']) ?></span></div></div></td>
            <td><?= e($x['employer_name']) ?></td>
            <td><span class="st <?= e($jc) ?>"><?= e($jl) ?></span></td>
            <td><?= e(date('M j, Y', strtotime($x['deadline']))) ?>
              <?php if ($live): ?><div class="ap-wait<?= $dl <= 3 ? ' late' : '' ?>"><?= icon('clock', 12) ?> <?= $dl <= 0 ? 'Ends today' : $dl . ' day' . ($dl > 1 ? 's' : '') . ' left' ?></div><?php endif; ?></td>
            <td><span class="ap-num"><?= icon('eye', 14) ?> <?= number_format((int)$x['views']) ?></span></td>
            <td><span class="ap-num<?= (int)$x['apps'] > 0 ? ' hot' : '' ?>"><?= icon('users', 14) ?> <?= number_format((int)$x['apps']) ?></span></td>
            <td class="ap-act"><a class="btn btn-outline btn-sm" href="<?= url('admin/job-review.php?id=' . (int)$x['id']) ?>">Open <?= icon('arrow', 14) ?></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>
    <?= pager($total, $page, $per, $base) ?>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>