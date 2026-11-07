<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require_role('seeker');
$pdo = db(); $uid = (int)$_SESSION['uid'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check() && ($_POST['action'] ?? '') === 'withdraw') {
    // Ownership check: only your own application, and only while Pending or Reviewed
    $u = $pdo->prepare("UPDATE job_applications SET status = 'withdrawn' WHERE id = ? AND seeker_id = ? AND status IN ('pending','reviewed')");
    $u->execute([(int)($_POST['id'] ?? 0), $uid]);
    flash($u->rowCount() ? 'success' : 'error', $u->rowCount() ? 'Your application was withdrawn.' : 'That application can no longer be withdrawn.');
    redirect('seeker/applications.php');
}

$filter = $_GET['status'] ?? '';
$valid = ['pending', 'reviewed', 'shortlisted', 'accepted', 'rejected', 'withdrawn'];
$sql = 'SELECT a.id, a.status, a.applied_at, a.updated_at, a.employer_message, j.id AS job_id, j.title, j.status AS job_status, j.deadline,
        ep.employer_name, ep.logo_path, b.name AS barangay,
        CASE WHEN a.status IN (\'shortlisted\',\'accepted\') THEN ep.contact_person   END AS c_person,
        CASE WHEN a.status IN (\'shortlisted\',\'accepted\') THEN ep.contact_position END AS c_position,
        CASE WHEN a.status IN (\'shortlisted\',\'accepted\') THEN ep.contact_phone    END AS c_phone
    FROM job_applications a JOIN job_posts j ON j.id = a.job_id
    JOIN employer_profiles ep ON ep.user_id = j.employer_id JOIN barangays b ON b.id = j.barangay_id
    WHERE a.seeker_id = :u';
$p = ['u' => $uid];
if (in_array($filter, $valid, true)) { $sql .= ' AND a.status = :s'; $p['s'] = $filter; }
$q = $pdo->prepare($sql . ' ORDER BY a.applied_at DESC');
$q->execute($p);
$apps = $q->fetchAll();

$pos = ['pending' => 0, 'reviewed' => 1, 'shortlisted' => 2, 'accepted' => 3, 'rejected' => 3];
// Counts for the tabs and the header strip
$cq = $pdo->prepare('SELECT status, COUNT(*) AS c FROM job_applications WHERE seeker_id = ? GROUP BY status');
$cq->execute([$uid]);
$counts = array_column($cq->fetchAll(), 'c', 'status');
$totalApps  = array_sum($counts);
$inProgress = ($counts['pending'] ?? 0) + ($counts['reviewed'] ?? 0) + ($counts['shortlisted'] ?? 0);
$hints = [
    'pending'     => 'Waiting for the employer to review.',
    'reviewed'    => 'The employer has seen your application.',
    'shortlisted' => 'You were shortlisted. The employer may contact you, or you can use the contact details above.',
    'accepted'    => 'Congratulations! The employer accepted your application.',
    'rejected'    => 'Not selected this time. Keep applying.',
    'withdrawn'   => 'You withdrew this application.',
];

$pageTitle = 'My Applications';
require __DIR__ . '/../includes/dash_top.php';
?>
<div class="apps">
<header class="ap-head">
  <div>
    <h1 class="dash-title ap-title">My applications</h1>
    <p class="ap-sub">Track every job you applied for.</p>
  </div>
  <?php if ($totalApps > 0): ?>
  <span class="ap-pills">
    <span class="ap-total"><b><?= $totalApps ?></b> <?= $totalApps === 1 ? 'application' : 'applications' ?></span>
    <?php if ($inProgress > 0): ?><span class="ap-pill"><b><?= $inProgress ?></b> in progress</span><?php endif; ?>
    <?php if (!empty($counts['accepted'])): ?><span class="ap-pill ap-good"><?= icon('check', 14) ?> <b><?= (int)$counts['accepted'] ?></b> accepted</span><?php endif; ?>
  </span>
  <?php endif; ?>
</header>

<div class="filter-tabs ap-tabs">
  <a href="<?= url('seeker/applications.php') ?>" class="<?= $filter === '' ? 'on' : '' ?>">All<?php if ($totalApps): ?> <em><?= $totalApps ?></em><?php endif; ?></a>
  <?php foreach ($valid as $st): ?><a href="<?= url('seeker/applications.php?status=' . $st) ?>" class="<?= $filter === $st ? 'on' : '' ?>"><?= e(app_status($st)[0]) ?><?php if (!empty($counts[$st])): ?> <em><?= (int)$counts[$st] ?></em><?php endif; ?></a><?php endforeach; ?>
</div>

<?php if (!$apps): ?>
  <div class="card empty"><img src="<?= url('assets/img/empty-no-applications.svg') ?>" alt="" width="140" onerror="this.style.display='none'">
    <h3>No applications here</h3><p><?= $filter ? 'Nothing with this status yet.' : 'When you apply for a job, it will show up here.' ?></p><a class="btn btn-primary" href="<?= url('seeker/browse.php') ?>">Find Jobs</a></div>
<?php else: ?>
  <div class="ap-list">
  <?php foreach ($apps as $a):
    [$lbl, $cls] = app_status($a['status']);
    $p0 = $pos[$a['status']] ?? -1;
    $stillOpen = $a['job_status'] === 'approved' && $a['deadline'] >= date('Y-m-d');
    $canWithdraw = in_array($a['status'], ['pending', 'reviewed'], true);
    $steps = ['Pending', 'Reviewed', 'Shortlisted', $a['status'] === 'rejected' ? 'Rejected' : 'Accepted']; ?>
    <article class="card ap-card a-<?= e($a['status']) ?>">
      <div class="ap-top">
        <div class="avatar ap-av" aria-hidden="true"><?php if (!empty($a['logo_path'])): ?><img src="<?= url('uploads/' . $a['logo_path']) ?>" alt=""><?php else: ?><?= e(initials($a['employer_name'])) ?><?php endif; ?></div>
        <div class="ap-main">
          <h3><?= $stillOpen ? '<a href="' . url('job.php?id=' . (int)$a['job_id']) . '">' . e($a['title']) . '</a>' : e($a['title']) ?></h3>
          <p class="ap-meta"><?= e($a['employer_name']) ?> &middot; <?= icon('pin', 13) ?> <?= e($a['barangay']) ?> &middot; applied <?= e(date('M j, Y', strtotime($a['applied_at']))) ?></p>
        </div>
        <span class="st <?= e($cls) ?>"><?= e($lbl) ?></span>
      </div>

      <?php if ($p0 >= 0): ?>
        <ol class="line">
          <?php foreach ($steps as $i => $name): ?><li class="<?= $i < $p0 ? 'done' : ($i === $p0 ? 'now ' . ($a['status'] === 'rejected' ? 'bad' : '') : '') ?>"><?= e($name) ?></li><?php endforeach; ?>
        </ol>
      <?php endif; ?>

      <?php if ($a['employer_message']): ?><p class="ap-msg"><b>Message from the employer:</b> <?= e($a['employer_message']) ?></p><?php endif; ?>

      <?php // Employer contact is only loaded by the query above once the application is Shortlisted or Accepted
      if (!empty($a['c_phone']) || !empty($a['c_person'])): ?>
      <div class="ap-contact">
        <b class="ap-contact-h"><?= icon('shield', 15) ?> Employer contact</b>
        <div class="ap-contact-row">
          <?php if (!empty($a['c_person'])): ?><span><small>Contact person</small><strong><?= e($a['c_person']) ?><?= !empty($a['c_position']) ? ' <em>(' . e($a['c_position']) . ')</em>' : '' ?></strong></span><?php endif; ?>
          <?php if (!empty($a['c_phone'])): ?><span><small>Mobile number</small><a href="tel:<?= e($a['c_phone']) ?>"><?= e($a['c_phone']) ?></a></span>
            <span class="ap-contact-btns"><a class="btn btn-primary btn-sm" href="tel:<?= e($a['c_phone']) ?>">Call</a><a class="btn btn-outline btn-sm" href="sms:<?= e($a['c_phone']) ?>">Text</a></span><?php endif; ?>
        </div>
        <p class="ap-contact-tip">Contact them only about this job. A real employer never asks you to pay money to get hired.</p>
      </div>
      <?php endif; ?>

      <div class="ap-foot">
        <span class="ap-hint"><?= e($hints[$a['status']] ?? '') ?><?php if ($a['status'] !== 'pending' && $a['updated_at'] !== $a['applied_at']): ?> <small>Updated <?= e(date('M j', strtotime($a['updated_at']))) ?></small><?php endif; ?></span>
        <?php if ($stillOpen || $canWithdraw): ?>
        <span class="ap-actions">
          <?php if ($stillOpen): ?><a class="btn btn-outline btn-sm" href="<?= url('job.php?id=' . (int)$a['job_id']) ?>">View job</a><?php endif; ?>
          <?php if ($canWithdraw): ?>
          <form method="post" data-confirm="You will not be able to apply to this job again." data-confirm-title="Withdraw application?" data-confirm-ok="Withdraw" data-confirm-tone="danger" data-confirm-cancel="Keep application">
            <?= csrf_field() ?><input type="hidden" name="action" value="withdraw"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
            <button class="btn btn-outline btn-sm ap-withdraw" type="submit">Withdraw</button>
          </form>
          <?php endif; ?>
        </span>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>