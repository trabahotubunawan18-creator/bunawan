<?php
require __DIR__ . '/../includes/admin_auth.php';
require_admin();
$pdo = db();
$n = fn(string $sql) => (int)$pdo->query($sql)->fetchColumn();

$pendingVerif = $n("SELECT COUNT(*) FROM employer_profiles WHERE verification_status = 'pending'");
$moreInfo     = $n("SELECT COUNT(*) FROM employer_profiles WHERE verification_status = 'more_info'");
$flagged      = $n("SELECT COUNT(*) FROM job_posts WHERE status = 'pending'");
$openReports  = $n("SELECT COUNT(*) FROM job_reports WHERE status = 'open'");
$activeJobs   = $n("SELECT COUNT(*) FROM job_posts WHERE status = 'approved' AND deadline >= CURRENT_DATE");
$seekers      = $n("SELECT COUNT(*) FROM users WHERE role = 'seeker'");
$employers    = $n("SELECT COUNT(*) FROM users WHERE role = 'employer'");
$premium      = $n("SELECT COUNT(DISTINCT employer_id) FROM premium_subscriptions WHERE status = 'active' AND ends_on >= CURRENT_DATE");
$autoToday    = $n("SELECT COUNT(*) FROM job_posts WHERE status = 'approved' AND DATE(approved_at) = CURRENT_DATE AND (red_flags IS NULL OR red_flags = '')");

$queue = $pdo->query("SELECT ep.user_id, ep.employer_name, ep.logo_path, ep.employer_type, ep.created_at, b.name AS barangay
    FROM employer_profiles ep JOIN barangays b ON b.id = ep.barangay_id WHERE ep.verification_status = 'pending'
    ORDER BY ep.created_at ASC LIMIT 5")->fetchAll();
$expiring = $pdo->query("SELECT user_id, employer_name, permit_expiry FROM employer_profiles
    WHERE verification_status = 'verified_business' AND permit_expiry IS NOT NULL AND permit_expiry <= (CURRENT_DATE + INTERVAL '30 days')
    ORDER BY permit_expiry ASC LIMIT 5")->fetchAll();
$activity = $pdo->query('SELECT l.action, l.target_type, l.target_id, l.reason, l.created_at, u.full_name FROM admin_activity_logs l
    JOIN users u ON u.id = l.admin_id ORDER BY l.id DESC LIMIT 6')->fetchAll();

// Charts
$byBarangay = $pdo->query("SELECT b.name AS label, COUNT(j.id) AS v FROM barangays b
    LEFT JOIN job_posts j ON j.barangay_id = b.id AND j.status = 'approved' AND j.deadline >= CURRENT_DATE
    WHERE b.is_active = 1 GROUP BY b.id, b.name ORDER BY v DESC, b.name LIMIT 10")->fetchAll();
$byCategory = $pdo->query("SELECT c.name AS label, COUNT(j.id) AS v FROM job_categories c
    LEFT JOIN job_posts j ON j.category_id = c.id AND j.status = 'approved' AND j.deadline >= CURRENT_DATE
    WHERE c.is_active = 1 GROUP BY c.id, c.name ORDER BY v DESC, c.sort_order LIMIT 10")->fetchAll();
$months = [];
for ($i = 5; $i >= 0; $i--) $months[date('Y-m', strtotime("first day of -$i month"))] = ['label' => date('M', strtotime("first day of -$i month")), 'jobs' => 0, 'apps' => 0];
$from = array_key_first($months) . '-01';
foreach ($pdo->query("SELECT TO_CHAR(created_at, 'YYYY-MM') m, COUNT(*) c FROM job_posts WHERE created_at >= '$from' GROUP BY m") as $r) if (isset($months[$r['m']])) $months[$r['m']]['jobs'] = (int)$r['c'];
foreach ($pdo->query("SELECT TO_CHAR(applied_at, 'YYYY-MM') m, COUNT(*) c FROM job_applications WHERE applied_at >= '$from' GROUP BY m") as $r) if (isset($months[$r['m']])) $months[$r['m']]['apps'] = (int)$r['c'];
$maxMonth = max(1, ...array_map(fn($m) => max($m['jobs'], $m['apps']), array_values($months)));

function dsh_bars(array $rows, string $tone = 'g'): void {
    $max = max(1, ...array_map(fn($r) => (int)$r['v'], $rows ?: [['v' => 1]]));
    if (!$rows) { echo '<div class="dsh-empty-s">No data yet.</div>'; return; }
    foreach ($rows as $r) { $w = round($r['v'] / $max * 100); ?>
      <div class="dsh-bar"><span class="bl" title="<?= e($r['label']) ?>"><?= e($r['label']) ?></span><span class="bt"><i class="t-<?= $tone ?>" style="width:<?= max($r['v'] > 0 ? 4 : 0, $w) ?>%"></i></span><b><?= (int)$r['v'] ?></b></div>
    <?php }
}
function dsh_act_icon(string $a): string {
    $a = strtolower($a);
    foreach (['login' => 'user', 'verif' => 'shield', 'setting' => 'cog', 'barangay' => 'pin', 'message' => 'mail', 'premium' => 'star', 'categor' => 'tag', 'complaint' => 'flag', 'report' => 'flag', 'job' => 'briefcase', 'user' => 'users', 'employer' => 'building'] as $k => $ic) if (strpos($a, $k) !== false) return $ic;
    return 'clock';
}

$attention = $pendingVerif + $flagged + $openReports;
$hr = (int)date('G');
$greet = $hr < 12 ? 'Good morning' : ($hr < 18 ? 'Good afternoon' : 'Good evening');
$first = explode(' ', trim($_SESSION['name'] ?? 'Admin'))[0];
$typeLabels = ['registered_business' => 'Business', 'organization' => 'Organization', 'individual' => 'Individual'];

$pageTitle = 'Dashboard';
require __DIR__ . '/../includes/admin_top.php';
?>
<div class="dsh">
  <section class="dsh-hero">
    <div>
      <h1><?= e($greet) ?>, <?= e($first) ?></h1>
      <p class="dsh-date"><?= e(date('l, F j, Y')) ?> &middot; Here is what is happening on Trabaho Tu Bunawan.</p>
      <?php if ($attention > 0): ?>
        <span class="dsh-sum"><?= icon('bell', 15) ?> <?= $attention ?> item<?= $attention > 1 ? 's' : '' ?> need<?= $attention > 1 ? '' : 's' ?> your attention</span>
      <?php else: ?>
        <span class="dsh-sum ok"><?= icon('check', 15) ?> Everything is up to date</span>
      <?php endif; ?>
    </div>
    <div class="dsh-hero-act">
      <a class="btn btn-gold btn-sm" href="<?= url('admin/verification.php') ?>"><?= icon('shield', 16) ?> Review employers</a>
      <a class="btn btn-white btn-sm" href="<?= url('admin/flagged.php') ?>"><?= icon('list', 16) ?> Flagged posts</a>
    </div>
  </section>

  <h2 class="dsh-title">Needs attention</h2>
  <div class="dsh-attn">
    <a class="dsh-a a-gold<?= $pendingVerif ? '' : ' zero' ?>" href="<?= url('admin/verification.php') ?>"><span class="ic"><?= icon('shield', 22) ?></span><div><strong><?= $pendingVerif ?></strong><span class="l">Pending verifications</span></div><span class="go"><?= icon('arrow', 16) ?></span></a>
    <a class="dsh-a a-amber<?= $flagged ? '' : ' zero' ?>" href="<?= url('admin/flagged.php') ?>"><span class="ic"><?= icon('list', 22) ?></span><div><strong><?= $flagged ?></strong><span class="l">Flagged posts</span></div><span class="go"><?= icon('arrow', 16) ?></span></a>
    <a class="dsh-a a-rose<?= $openReports ? '' : ' zero' ?>" href="<?= url('admin/complaints.php') ?>"><span class="ic"><?= icon('flag', 22) ?></span><div><strong><?= $openReports ?></strong><span class="l">Open reports</span></div><span class="go"><?= icon('arrow', 16) ?></span></a>
  </div>

  <h2 class="dsh-title">Platform overview</h2>
  <div class="dsh-stats">
    <div class="dsh-s"><span class="ic s-green"><?= icon('briefcase', 24) ?></span><div><strong><?= $activeJobs ?></strong><span class="l">Active jobs</span></div></div>
    <div class="dsh-s"><span class="ic s-blue"><?= icon('users', 24) ?></span><div><strong><?= $seekers + $employers ?></strong><span class="l">Total users</span><small><?= $seekers ?> job seekers &middot; <?= $employers ?> employers</small></div></div>
    <div class="dsh-s"><span class="ic s-gold"><?= icon('star', 24) ?></span><div><strong><?= $premium ?></strong><span class="l">Premium employers</span></div></div>
    <div class="dsh-s"><span class="ic s-leaf"><?= icon('check', 24) ?></span><div><strong><?= $autoToday ?></strong><span class="l">Auto-published today</span></div></div>
  </div>

  <div class="dsh-grid">
    <div class="dsh-col">
      <div class="dsh-panel">
        <div class="dsh-ph"><h2>Needs review</h2><a class="more" href="<?= url('admin/verification.php') ?>">Open queue &rarr;</a></div>
        <?php if ($queue): foreach ($queue as $q): $wd = (int)floor((time() - strtotime($q['created_at'])) / 86400); ?>
          <div class="dsh-row">
            <span class="dsh-av"><?= avatar_inner($q['logo_path'] ?? null, $q['employer_name']) ?></span>
            <div class="who"><b><?= e($q['employer_name']) ?></b><span><?= e($typeLabels[$q['employer_type']] ?? 'Employer') ?> &middot; <?= e($q['barangay']) ?> &middot; since <?= e(date('M j', strtotime($q['created_at']))) ?></span></div>
            <span class="dsh-wait<?= $wd >= 3 ? ' late' : '' ?>"><?= $wd < 1 ? 'Today' : $wd . ' day' . ($wd > 1 ? 's' : '') ?></span>
            <a class="btn btn-primary btn-sm" href="<?= url('admin/verify-review.php?id=' . (int)$q['user_id']) ?>">Review</a>
          </div>
        <?php endforeach; else: ?>
          <div class="dsh-empty"><span class="ring"><?= icon('check', 28) ?></span><p>No employers are waiting for review.</p></div>
        <?php endif; ?>
        <?php if ($moreInfo): ?><p class="dsh-note"><?= $moreInfo ?> employer<?= $moreInfo > 1 ? 's are' : ' is' ?> waiting to send more information.</p><?php endif; ?>
      </div>

      <div class="dsh-panel">
        <div class="dsh-ph"><h2>Jobs and applications</h2><span class="dsh-legend"><span><i class="lg-g"></i>Jobs posted</span><span><i class="lg-y"></i>Applications</span></span></div>
        <div class="dsh-months">
          <?php foreach ($months as $m): ?>
            <div class="dsh-mc">
              <div class="dsh-mbars">
                <i class="g<?= $m['jobs'] ? '' : ' z' ?>" style="height:<?= max(3, round($m['jobs'] / $maxMonth * 100)) ?>%" title="<?= $m['jobs'] ?> jobs"></i>
                <i class="y<?= $m['apps'] ? '' : ' z' ?>" style="height:<?= max(3, round($m['apps'] / $maxMonth * 100)) ?>%" title="<?= $m['apps'] ?> applications"></i>
              </div>
              <b><?= $m['jobs'] ?> / <?= $m['apps'] ?></b><span><?= e($m['label']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="dsh-col">
      <div class="dsh-panel">
        <div class="dsh-ph"><h2>Quick actions</h2></div>
        <div class="dsh-qa">
          <a href="<?= url('admin/jobs.php') ?>"><span class="qi"><?= icon('briefcase', 18) ?></span>Jobs</a>
          <a href="<?= url('admin/users.php') ?>"><span class="qi"><?= icon('users', 18) ?></span>Users</a>
          <a href="<?= url('admin/complaints.php') ?>"><span class="qi"><?= icon('flag', 18) ?></span>Complaints<?php if ($openReports): ?><em class="n"><?= $openReports ?></em><?php endif; ?></a>
          <a href="<?= url('admin/reports.php') ?>"><span class="qi"><?= icon('chart', 18) ?></span>Reports</a>
          <a href="<?= url('admin/settings.php') ?>"><span class="qi"><?= icon('cog', 18) ?></span>Settings</a>
        </div>
      </div>

      <?php if ($expiring): ?>
      <div class="dsh-panel dsh-permit">
        <div class="dsh-ph"><h2>Permits expiring soon</h2></div>
        <?php foreach ($expiring as $x): $gone = $x['permit_expiry'] < date('Y-m-d'); ?>
          <div class="dsh-row">
            <span class="dsh-av"><?= icon('clock', 18) ?></span>
            <div class="who"><b><?= e($x['employer_name']) ?></b><span><?= $gone ? 'Expired' : 'Expires' ?> <?= e(date('M j, Y', strtotime($x['permit_expiry']))) ?></span></div>
            <a class="btn btn-outline btn-sm" href="<?= url('admin/verify-review.php?id=' . (int)$x['user_id']) ?>">Open</a>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="dsh-panel">
        <div class="dsh-ph"><h2>Recent activity</h2><a class="more" href="<?= url('admin/activity-log.php') ?>">See all &rarr;</a></div>
        <?php if ($activity): ?>
          <ul class="dsh-tl">
            <?php foreach ($activity as $a): ?>
              <li><span class="dot"><?= icon(dsh_act_icon($a['action']), 16) ?></span>
                <div><b><?= e(action_label($a['action'])) ?></b>
                  <div class="meta"><?= e($a['full_name']) ?> &middot; <?= e(date('M j, g:i A', strtotime($a['created_at']))) ?><?= $a['target_type'] ? ' <em>&middot; ' . e($a['target_type']) . ' #' . (int)$a['target_id'] . '</em>' : '' ?></div></div></li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="dsh-empty"><span class="ring"><?= icon('file', 26) ?></span><p>No activity yet.</p></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="dsh-grid two">
    <div class="dsh-panel"><div class="dsh-ph"><h2>Active jobs by barangay</h2></div><?php dsh_bars($byBarangay, 'g'); ?></div>
    <div class="dsh-panel"><div class="dsh-ph"><h2>Active jobs by category</h2></div><?php dsh_bars($byCategory, 'y'); ?></div>
  </div>
</div>
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>
