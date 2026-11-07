<?php
require __DIR__ . '/../includes/admin_auth.php';
require __DIR__ . '/../includes/redflags.php';
require_admin();
$pdo = db();
$page = max(1, (int)($_GET['page'] ?? 1)); $per = 15;
$total = (int)$pdo->query("SELECT COUNT(*) FROM job_posts WHERE status = 'pending'")->fetchColumn();
$r = $pdo->query("SELECT j.id, j.title, j.red_flags, j.created_at, ep.employer_name, ep.verification_status, b.name AS barangay,
        (SELECT COUNT(*) FROM job_reports jr WHERE jr.job_id = j.id AND jr.status = 'open') AS reports
    FROM job_posts j JOIN employer_profiles ep ON ep.user_id = j.employer_id JOIN barangays b ON b.id = j.barangay_id
    WHERE j.status = 'pending' ORDER BY j.created_at ASC LIMIT $per OFFSET " . (($page - 1) * $per));
$rows = $r->fetchAll();
// ---------- Export: Print, Save as PDF, Save as CSV (every flagged post, not only this page) ----------
require_once __DIR__ . '/../includes/admin_export.php';
$xr = $pdo->query("SELECT j.title, j.red_flags, j.created_at, ep.employer_name, ep.verification_status, b.name AS barangay,
        (SELECT COUNT(*) FROM job_reports jr WHERE jr.job_id = j.id AND jr.status = 'open') AS reports
    FROM job_posts j JOIN employer_profiles ep ON ep.user_id = j.employer_id JOIN barangays b ON b.id = j.barangay_id
    WHERE j.status = 'pending' ORDER BY j.created_at ASC LIMIT " . XP_MAX_ROWS);
$xpRows = []; $xn = 0;
foreach ($xr as $x) $xpRows[] = [++$xn, $x['title'], $x['employer_name'], $x['barangay'], verif_info($x['verification_status'])[0],
    implode('; ', flag_codes_to_text($x['red_flags'])) ?: 'Reported by users', (int)$x['reports'],
    date('M j, Y', strtotime($x['created_at'])), (int)floor((time() - strtotime($x['created_at'])) / 86400)];
$xp = ['title' => 'Flagged Posts Report', 'slug' => 'flagged-posts',
    'purpose' => 'Job posts held back for review by the red-flag checker or by user complaints, oldest first.',
    'filters' => ['Status' => 'Waiting for review'],
    'head' => ['No.', 'Job title', 'Employer', 'Barangay', 'Employer status', 'Why it was held', 'Open complaints', 'Date submitted', 'Days waiting'],
    'rows' => $xpRows, 'right' => [6, 8], 'landscape' => true];

$pageTitle = 'Flagged posts';
require __DIR__ . '/../includes/admin_top.php';
?>
<div class="ap">
  <section class="ap-hero">
    <div>
      <h1>Flagged posts</h1>
      <p>Jobs held back by the red-flag checker or by reports. Clean posts publish automatically and do not appear here.</p>
    </div>
    <?php if ($total > 0): ?>
      <span class="ap-pill"><?= icon('flag', 15) ?> <?= $total ?> post<?= $total > 1 ? 's' : '' ?> to review</span>
    <?php else: ?>
      <span class="ap-pill ok"><?= icon('check', 15) ?> All caught up</span>
    <?php endif; ?>
  </section>


  <?php if (!$rows): ?>
    <div class="ap-empty">
      <span class="ring"><?= icon('check', 32) ?></span>
      <h3>Nothing to review</h3>
      <p>No posts are waiting. New flagged posts will show up here.</p>
      <div class="ap-empty-act">
        <a class="btn btn-primary btn-sm" href="<?= url('admin/jobs.php') ?>"><?= icon('briefcase', 15) ?> View all jobs</a>
        <a class="btn btn-outline btn-sm" href="<?= url('admin/index.php') ?>">Back to dashboard</a>
      </div>
    </div>
  <?php else: foreach ($rows as $x): $reasons = flag_codes_to_text($x['red_flags']);
      $wd = (int)floor((time() - strtotime($x['created_at'])) / 86400); ?>
    <article class="ap-flag<?= $x['reports'] > 0 ? ' has-report' : '' ?>">
      <span class="ap-flag-ico"><?= icon($x['reports'] > 0 ? 'flag' : 'list', 22) ?></span>
      <div class="ap-flag-body">
        <div class="ap-flag-top">
          <h3><?= e($x['title']) ?></h3>
          <span class="ap-wait<?= $wd >= 2 ? ' late' : '' ?>"><?= icon('clock', 12) ?> <?= $wd < 1 ? 'Today' : $wd . ' day' . ($wd > 1 ? 's' : '') . ' ago' ?></span>
        </div>
        <div class="ap-flag-meta">
          <span><?= icon('building', 14) ?> <?= e($x['employer_name']) ?></span>
          <span><?= icon('pin', 14) ?> <?= e($x['barangay']) ?></span>
          <span><?= icon('calendar', 14) ?> <?= e(date('M j, g:i A', strtotime($x['created_at']))) ?></span>
        </div>
        <div class="ap-flags">
          <?php foreach ($reasons as $c => $m): ?><span class="ap-flagchip" title="<?= e(is_string($m) ? $m : '') ?>"><?= icon('flag', 12) ?> <?= e($c === 'reported' ? 'Reported' : ucfirst($c)) ?></span><?php endforeach; ?>
          <?php if ($x['reports'] > 0): ?><span class="ap-flagchip rose"><?= icon('flag', 12) ?> <?= (int)$x['reports'] ?> open report<?= $x['reports'] > 1 ? 's' : '' ?></span><?php endif; ?>
          <?php if (!$reasons && !$x['reports']): ?><span class="ap-flagchip soft">Held because of manual review mode</span><?php endif; ?>
        </div>
      </div>
      <a class="btn btn-primary btn-sm ap-flag-btn" href="<?= url('admin/job-review.php?id=' . (int)$x['id'] . '&from=flagged') ?>">Review <?= icon('arrow', 14) ?></a>
    </article>
  <?php endforeach; endif; ?>
  <?= pager($total, $page, $per, url('admin/flagged.php')) ?>
</div>
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>