<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require_role('employer');
$pdo = db(); $uid = (int)$_SESSION['uid'];

$s = $pdo->prepare('SELECT ep.*, b.name AS barangay FROM employer_profiles ep JOIN barangays b ON b.id = ep.barangay_id WHERE ep.user_id = ?');
$s->execute([$uid]);
$p = $s->fetch();
if (!$p) { http_response_code(404); exit('Employer profile not found.'); }
$status = $p['verification_status'];
[$vLabel, $vClass, $canPost] = verif_info($status);

$one = function (string $sql) use ($pdo, $uid) { $q = $pdo->prepare($sql); $q->execute([$uid]); return (int)$q->fetchColumn(); };
$activeJobs  = $one("SELECT COUNT(*) FROM job_posts WHERE employer_id = ? AND status='approved' AND deadline >= CURRENT_DATE");
$pendingJobs = $one("SELECT COUNT(*) FROM job_posts WHERE employer_id = ? AND status='pending'");
$rejectedJobs = $one("SELECT COUNT(*) FROM job_posts WHERE employer_id = ? AND status='rejected'");
$newApps     = $one("SELECT COUNT(*) FROM job_applications a JOIN job_posts j ON j.id = a.job_id WHERE j.employer_id = ? AND a.applied_at >= (NOW() - INTERVAL '7 days')");
$pendingApps = $one("SELECT COUNT(*) FROM job_applications a JOIN job_posts j ON j.id = a.job_id WHERE j.employer_id = ? AND a.status = 'pending'");
$views       = $one("SELECT COALESCE(SUM(views),0) FROM job_posts WHERE employer_id = ?");

// "Needs your action" list
$needs = [];
if ($status === 'more_info') $needs[] = ['Our team asked for more information. Upload your documents again.', 'employer/verification.php', 'Open verification'];
if ($status === 'rejected')  $needs[] = ['Your verification was not approved. Read the reason and resubmit.', 'employer/verification.php', 'See reason'];
if ($pendingApps > 0)  $needs[] = [$pendingApps . ' applicant' . ($pendingApps > 1 ? 's are' : ' is') . ' waiting for your review.', 'employer/applicants.php', 'Review applicants'];
if ($rejectedJobs > 0) $needs[] = [$rejectedJobs . ' job post' . ($rejectedJobs > 1 ? 's were' : ' was') . ' not approved. Fix and resubmit.', 'employer/my-jobs.php?tab=rejected', 'See posts'];
if ($status === 'verified_business' && $p['permit_expiry'] && days_left($p['permit_expiry']) <= 30)
    $needs[] = ['Your business permit ' . (days_left($p['permit_expiry']) < 0 ? 'has expired' : 'expires on ' . date('M j, Y', strtotime($p['permit_expiry']))) . '. Contact us to renew your verification.', 'contact.php', 'Contact us'];

$ra = $pdo->prepare('SELECT a.applicant_name, a.applied_at, a.status, j.title FROM job_applications a JOIN job_posts j ON j.id = a.job_id
    WHERE j.employer_id = ? ORDER BY a.applied_at DESC LIMIT 5');
$ra->execute([$uid]);
$recent = $ra->fetchAll();

$tj = $pdo->prepare('SELECT j.id, j.title, j.status, j.deadline, j.views, COUNT(a.id) AS apps FROM job_posts j
    LEFT JOIN job_applications a ON a.job_id = j.id WHERE j.employer_id = ? GROUP BY j.id, j.title, j.status, j.deadline, j.views
    ORDER BY j.created_at DESC LIMIT 5');
$tj->execute([$uid]);
$jobs = $tj->fetchAll();

$msgs = [
    'pending' => 'Your application is under review. Our team will check your details, call your contact number and a reference, then decide.',
    'more_info' => 'Our team needs a little more information before approving you.',
    'rejected' => 'Your verification was not approved.',
    'suspended' => 'Your account is suspended and cannot post jobs. Please contact us.',
];
$stage = in_array($status, ['pending', 'more_info'], true) ? 2 : 3;
$pageTitle = 'Overview';
require __DIR__ . '/../includes/emp_top.php';
?>
<section class="welcome">
  <div>
    <p class="welcome-kicker">Welcome back</p>
    <h1><?= e($p['employer_name']) ?></h1>
    <p><?= $canPost ? 'You are verified. Post a vacancy and meet local talent.' : 'Finish verification to start posting jobs.' ?></p>
    <div class="welcome-cta">
      <?php if ($canPost): ?><a class="btn btn-gold" href="<?= url('employer/post-job.php') ?>"><?= icon('plus', 16) ?> Post a Job</a>
      <?php else: ?><a class="btn btn-white" href="<?= url('employer/verification.php') ?>">Check verification</a><?php endif; ?>
      <a class="btn btn-outline-gold" href="<?= url('employer/company-profile.php') ?>">Company profile</a>
    </div>
  </div>
</section>

<?php if (!$canPost): ?>
<div class="vbanner <?= $vClass === 'vs-bad' ? 'vb-bad' : 'vb-pending' ?>">
  <div style="flex:1">
    <h2 style="margin:0 0 4px;font-size:18px">Verification: <?= e($vLabel) ?></h2>
    <p style="margin:0"><?= e($msgs[$status] ?? '') ?></p>
    <?php if ($status === 'more_info' && $p['verification_notes']): ?><p class="note-box"><b>Note from our team:</b> <?= nl2br(e($p['verification_notes'])) ?></p><?php endif; ?>
    <?php if ($status === 'rejected' && $p['rejection_reason']): ?><p class="note-box"><b>Reason:</b> <?= nl2br(e($p['rejection_reason'])) ?></p><?php endif; ?>
  </div>
  <a class="btn btn-primary btn-sm" href="<?= url('employer/verification.php') ?>">View details</a>
</div>
<ol class="track" aria-label="Verification progress" style="margin-bottom:22px">
  <li class="done">Application submitted</li>
  <li class="<?= $stage === 2 ? 'now' : 'done' ?>">Under review</li>
  <li class="<?= $stage === 3 ? 'now' : '' ?>"><?= $stage === 3 ? e($vLabel) : 'Decision' ?></li>
</ol>
<?php endif; ?>

<div class="statcards">
  <div class="card statcard"><span class="si si-green"><?= icon('list', 22) ?></span><div><strong><?= $activeJobs ?></strong><span>Active jobs</span></div></div>
  <div class="card statcard"><span class="si si-gold"><?= icon('shield', 22) ?></span><div><strong><?= $pendingJobs ?></strong><span>Awaiting approval</span></div></div>
  <div class="card statcard"><span class="si si-leaf"><?= icon('users', 22) ?></span><div><strong><?= $newApps ?></strong><span>New applicants (7 days)</span></div></div>
  <div class="card statcard"><span class="si si-green"><?= icon('eye', 22) ?></span><div><strong><?= number_format($views) ?></strong><span>Total job views</span></div></div>
</div>

<div class="duo">
  <div class="card panel-card">
    <div class="panel-head"><h2>Needs your action</h2></div>
    <?php if ($needs): foreach ($needs as [$txt, $link, $btn]): ?>
      <div class="row-item"><div><?= e($txt) ?></div><a class="btn btn-outline btn-sm" href="<?= url($link) ?>"><?= e($btn) ?></a></div>
    <?php endforeach; else: ?>
      <div class="mini-empty"><?= icon('check', 28) ?><p>You are all caught up.</p></div>
    <?php endif; ?>
  </div>

  <div class="card panel-card">
    <div class="panel-head"><h2>Recent applicants</h2><a class="more" href="<?= url('employer/applicants.php') ?>">See all</a></div>
    <?php if ($recent): foreach ($recent as $a): [$lbl, $cls] = app_status($a['status']); ?>
      <div class="row-item">
        <div><b><?= e($a['applicant_name']) ?></b><div class="small"><?= e($a['title']) ?> &middot; <?= e(date('M j', strtotime($a['applied_at']))) ?></div></div>
        <span class="st <?= e($cls) ?>"><?= e($lbl) ?></span>
      </div>
    <?php endforeach; else: ?>
      <div class="mini-empty"><?= icon('users', 28) ?><p>No applicants yet.</p></div>
    <?php endif; ?>
  </div>
</div>

<div class="card panel-card" style="margin-top:20px">
  <div class="panel-head"><h2>Your job posts</h2><a class="more" href="<?= url('employer/my-jobs.php') ?>">Manage all</a></div>
  <?php if ($jobs): ?>
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th>Job</th><th>Status</th><th>Deadline</th><th>Views</th><th>Applicants</th></tr></thead>
      <tbody>
      <?php foreach ($jobs as $j):
        $isExp = $j['status'] === 'approved' && $j['deadline'] < date('Y-m-d');
        [$jl, $jc] = job_status($isExp ? 'expired' : $j['status']); ?>
        <tr><td><b><?= e($j['title']) ?></b></td><td><span class="st <?= e($jc) ?>"><?= e($jl) ?></span></td>
          <td><?= e(date('M j, Y', strtotime($j['deadline']))) ?></td><td><?= (int)$j['views'] ?></td><td><?= (int)$j['apps'] ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
  <?php else: ?>
    <div class="mini-empty"><?= icon('list', 28) ?><p><?= $canPost ? 'You have not posted a job yet.' : 'You can post jobs once you are verified.' ?></p>
      <?php if ($canPost): ?><a class="btn btn-primary btn-sm" href="<?= url('employer/post-job.php') ?>">Post your first job</a><?php endif; ?></div>
  <?php endif; ?>
</div>

<div class="promo">
  <div><b>Want more people to see your jobs?</b><p>Premium (₱49 per month for each post you choose) puts that post on the home page with a gold badge. Our admin activates it.</p></div>
  <a class="btn btn-gold btn-sm" href="<?= url('employer/premium.php') ?>">See Premium</a>
</div>
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>