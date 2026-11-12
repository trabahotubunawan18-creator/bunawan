<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require_role('seeker');
$pdo = db(); $uid = (int)$_SESSION['uid'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check() && ($_POST['action'] ?? '') === 'remove') {
    $pdo->prepare('DELETE FROM saved_jobs WHERE seeker_id = ? AND job_id = ?')->execute([$uid, (int)($_POST['job_id'] ?? 0)]);
    flash('success', 'Removed from your saved jobs.');
    redirect('seeker/saved.php');
}

$s = $pdo->prepare('SELECT j.id, j.title, j.deadline, j.status, j.employment_type, j.salary_min, j.salary_max, j.salary_period,
        ep.employer_name, ep.logo_path, b.name AS barangay, sj.saved_at
    FROM saved_jobs sj JOIN job_posts j ON j.id = sj.job_id
    JOIN employer_profiles ep ON ep.user_id = j.employer_id JOIN barangays b ON b.id = j.barangay_id
    WHERE sj.seeker_id = ? ORDER BY sj.saved_at DESC');
$s->execute([$uid]);
$jobs = $s->fetchAll();

// Counts for the header strip
$openCount = 0; $soonCount = 0;
foreach ($jobs as $x) { $dl = days_left($x['deadline']); if ($x['status'] === 'approved' && $dl >= 0) { $openCount++; if ($dl <= 3) $soonCount++; } }

$pageTitle = 'Saved Jobs';
require __DIR__ . '/../includes/dash_top.php';
?>
<div class="saved">
<header class="sv-head">
  <div>
    <h1 class="dash-title sv-title">Saved jobs</h1>
    <p class="sv-sub">Jobs you saved to look at later.</p>
  </div>
  <?php if ($jobs): ?>
  <span class="sv-pills">
    <span class="sv-count"><b><?= count($jobs) ?></b> saved</span>
    <?php if ($soonCount > 0): ?><span class="sv-soon"><?= icon('calendar', 14) ?> <b><?= $soonCount ?></b> closing soon</span><?php endif; ?>
  </span>
  <?php endif; ?>
</header>

<?php if (!$jobs): ?>
  <div class="card empty"><img src="<?= url('assets/img/empty-no-saved.svg') ?>" alt="" width="140" onerror="this.style.display='none'">
    <h3>No saved jobs yet</h3><p>Press <b>Save Job</b> on any listing to keep it here.</p><a class="btn btn-primary" href="<?= url('seeker/browse.php') ?>">Browse jobs</a></div>
<?php else: ?>
  <div class="sv-list">
  <?php foreach ($jobs as $j):
    $left = days_left($j['deadline']);
    $open = $j['status'] === 'approved' && $left >= 0; ?>
    <article class="card sv-row<?= $open ? '' : ' is-closed' ?>">
      <div class="avatar sv-av" aria-hidden="true"><?php if (!empty($j['logo_path'])): ?><img src="<?= public_file_url($j['logo_path']) ?>" alt=""><?php else: ?><?= e(initials($j['employer_name'])) ?><?php endif; ?></div>
      <div class="sv-main">
        <h3><?= $open ? '<a href="' . url('job.php?id=' . (int)$j['id']) . '">' . e($j['title']) . '</a>' : e($j['title']) ?></h3>
        <p class="sv-meta"><?= e($j['employer_name']) ?> &middot; <?= icon('pin', 13) ?> <?= e($j['barangay']) ?> &middot; <?= e(type_label($j['employment_type'])) ?></p>
      </div>
      <span class="sv-salary"><?= e(salary_text($j)) ?></span>
      <div class="sv-when">
        <?php if (!$open): ?><span class="st st-off">Closed or expired</span>
        <?php elseif ($left <= 3): ?><span class="st st-pending">Expiring soon: <?= $left === 0 ? 'last day' : $left . ' day' . ($left > 1 ? 's' : '') . ' left' ?></span>
        <?php else: ?><span class="sv-days"><b><?= $left ?> days</b> left to apply</span><?php endif; ?>
        <small>Until <?= e(date('M j', strtotime($j['deadline']))) ?> &middot; saved <?= e(date('M j', strtotime($j['saved_at']))) ?></small>
      </div>
      <div class="sv-actions">
        <?php if ($open): ?><a class="btn btn-primary btn-sm" href="<?= url('job.php?id=' . (int)$j['id']) ?>">View and Apply</a><?php endif; ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="job_id" value="<?= (int)$j['id'] ?>">
          <button class="btn btn-outline btn-sm sv-remove" type="submit" title="Remove from saved jobs">Remove</button></form>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>
