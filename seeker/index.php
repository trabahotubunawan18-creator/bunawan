<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require_role('seeker');
$pdo = db(); $uid = (int)$_SESSION['uid'];

$s = $pdo->prepare('SELECT u.full_name, u.phone, sp.barangay_id, sp.education, sp.skills, sp.work_experience, sp.availability
    FROM users u LEFT JOIN seeker_profiles sp ON sp.user_id = u.id WHERE u.id = ?');
$s->execute([$uid]);
$me = $s->fetch();

// Profile checklist
$check = [
    ['Mobile number', !empty($me['phone'])],
    ['Barangay', !empty($me['barangay_id'])],
    ['Education', !empty($me['education'])],
    ['Skills', !empty($me['skills'])],
    ['Work experience', !empty($me['work_experience'])],
    ['Availability', !empty($me['availability'])],
];
$done = count(array_filter($check, fn($c) => $c[1]));
$pct = (int)round($done / count($check) * 100);

$one = function (string $sql) use ($pdo, $uid) { $q = $pdo->prepare($sql); $q->execute([$uid]); return (int)$q->fetchColumn(); };
$nApps  = $one('SELECT COUNT(*) FROM job_applications WHERE seeker_id = ?');
$nSaved = $one('SELECT COUNT(*) FROM saved_jobs WHERE seeker_id = ?');
$nShort = $one("SELECT COUNT(*) FROM job_applications WHERE seeker_id = ? AND status IN ('shortlisted','accepted')");

// Recommended = best skills match first, then your barangay, then Featured, then newest.
$myKeys = skill_list_map(seeker_skill_list($pdo, $uid));
$r = $pdo->prepare(PUBLIC_JOB_SQL . ' AND j.id NOT IN (SELECT job_id FROM job_applications WHERE seeker_id = :u) ORDER BY is_premium DESC, j.created_at DESC LIMIT 100');
$r->execute(['u' => $uid]);
$cand = $r->fetchAll();
attach_skill_info($pdo, $cand, $myKeys);
$recommended = array_slice(skill_sort_jobs($cand, !empty($me['barangay_id']) ? (int)$me['barangay_id'] : null), 0, 4);

$ra = $pdo->prepare('SELECT a.id, a.status, a.applied_at, j.id AS job_id, j.title, ep.employer_name
    FROM job_applications a JOIN job_posts j ON j.id = a.job_id JOIN employer_profiles ep ON ep.user_id = j.employer_id
    WHERE a.seeker_id = ? ORDER BY a.applied_at DESC LIMIT 5');
$ra->execute([$uid]);
$recent = $ra->fetchAll();

$hour = (int)date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$pageTitle = 'Overview';
require __DIR__ . '/../includes/dash_top.php';
?>
<section class="welcome">
  <div>
    <p class="welcome-kicker"><?= e($greet) ?></p>
    <h1><?= e($first) ?>, your next opportunity starts in Bunawan.</h1>
    <p>Find work close to home and track every application in one place.</p>
    <div class="welcome-cta">
      <a class="btn btn-white" href="<?= url('seeker/browse.php') ?>">Find Jobs</a>
      <?php if ($pct < 100): ?><a class="btn btn-outline-gold" href="<?= url('seeker/profile.php') ?>">Complete my profile</a><?php endif; ?>
    </div>
  </div>
</section>

<div class="statcards">
  <div class="card statcard"><span class="si si-green"><?= icon('send', 22) ?></span><div><strong><?= $nApps ?></strong><span>Applications</span></div></div>
  <div class="card statcard"><span class="si si-gold"><?= icon('bookmark', 22) ?></span><div><strong><?= $nSaved ?></strong><span>Saved jobs</span></div></div>
  <div class="card statcard"><span class="si si-leaf"><?= icon('star', 22) ?></span><div><strong><?= $nShort ?></strong><span>Shortlisted or accepted</span></div></div>
</div>

<div class="duo">
  <div class="card panel-card">
    <div class="panel-head"><h2>Your profile</h2><b class="pct"><?= $pct ?>%</b></div>
    <div class="bar"><span style="width:<?= $pct ?>%"></span></div>
    <ul class="todo">
      <?php foreach ($check as [$label, $ok]): ?>
        <li class="<?= $ok ? 'ok' : '' ?>"><span class="tick"><?= $ok ? icon('check', 14) : '' ?></span><?= e($label) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php if ($pct < 100): ?><a class="btn btn-primary btn-sm" href="<?= url('seeker/profile.php') ?>">Complete my profile</a>
    <?php else: ?><p class="small" style="margin:12px 0 0">Your profile is complete. Great job.</p><?php endif; ?>
  </div>

  <div class="card panel-card">
    <div class="panel-head"><h2>Recent applications</h2><a class="more" href="<?= url('seeker/applications.php') ?>">See all</a></div>
    <?php if ($recent): foreach ($recent as $a): [$lbl, $cls] = app_status($a['status']); ?>
      <div class="row-item">
        <div><b><?= e($a['title']) ?></b><div class="small"><?= e($a['employer_name']) ?> &middot; <?= e(date('M j', strtotime($a['applied_at']))) ?></div></div>
        <span class="st <?= e($cls) ?>"><?= e($lbl) ?></span>
      </div>
    <?php endforeach; else: ?>
      <div class="mini-empty"><?= icon('send', 28) ?><p>No applications yet.</p><a class="btn btn-outline btn-sm" href="<?= url('seeker/browse.php') ?>">Find a job</a></div>
    <?php endif; ?>
  </div>
</div>

<div class="sec-head" style="margin-top:30px"><div><h2 style="font-size:20px">Recommended for you</h2>
  <p class="sub"><?php if ($myKeys): ?>Matched to your skills, with jobs in your barangay first.<?php else: ?><a href="<?= url('seeker/profile.php') ?>">Add your skills</a> to your profile to get jobs matched to what you can do.<?php endif; ?></p></div>
  <a class="more" href="<?= url('seeker/browse.php') ?>">Browse all jobs <?= icon('arrow') ?></a></div>
<?php if ($recommended): ?>
  <div class="grid grid-2"><?php foreach ($recommended as $j) render_job_card($j, (bool)$j['is_premium']); ?></div>
<?php else: ?>
  <div class="card empty"><h3>No new jobs right now</h3><p>New vacancies are posted often. Check back soon.</p><a class="btn btn-primary" href="<?= url('seeker/browse.php') ?>">Browse jobs</a></div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>