<?php
// Public employer profile: employer-profile.php?id=<employer user id>
// Only verified employers are public. Admins can preview any employer (opened from the admin Employers list).
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

$id = (int)($_GET['id'] ?? 0);
$pdo = db();
$st = $pdo->prepare("SELECT ep.user_id, ep.employer_name, ep.employer_type, ep.description, ep.full_address, ep.landmark,
        ep.facebook_url, ep.logo_path, ep.verification_status, ep.peso_endorsed, b.name AS barangay,
        CASE WHEN EXISTS(SELECT 1 FROM premium_subscription_jobs psj JOIN premium_subscriptions ps ON ps.id = psj.subscription_id
               WHERE ps.employer_id = ep.user_id AND ps.status = 'active' AND ps.ends_on >= CURRENT_DATE) THEN 1 ELSE 0 END AS is_premium
    FROM employer_profiles ep
    JOIN barangays b ON b.id = ep.barangay_id
    WHERE ep.user_id = ?");
$st->execute([$id]);
$emp = $id > 0 ? $st->fetch() : false;

$isAdmin = (($_SESSION['role'] ?? '') === 'admin');
$isPublic = $emp && in_array($emp['verification_status'], ['verified_business', 'verified_local'], true);
if (!$emp || (!$isPublic && !$isAdmin)) { require __DIR__ . '/404.php'; exit; }

$js = $pdo->prepare(PUBLIC_JOB_SQL . ' AND j.employer_id = ? ORDER BY j.created_at DESC LIMIT 24');
$js->execute([$id]);
$jobs = $isPublic ? $js->fetchAll() : [];

$typeNames = ['registered_business' => 'Registered business', 'organization' => 'Organization', 'individual' => 'Individual or household'];
$fb = (string)$emp['facebook_url'];
if (!preg_match('#^https?://#i', $fb)) $fb = '';

$pageTitle = $emp['employer_name'];
$bodyClass = 'profile-page';
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/back_button.php';
?>
<section class="page-hero ep-hero">
  <div class="wrap">
    <?= back_button('jobs.php') ?>
    <div class="ep-head">
      <div class="avatar ep-avatar" aria-hidden="true">
        <?php if (!empty($emp['logo_path'])): ?><img src="<?= public_file_url($emp['logo_path']) ?>" alt=""><?php else: ?><?= e(initials($emp['employer_name'])) ?><?php endif; ?>
      </div>
      <div class="ep-id">
        <h1><?= e($emp['employer_name']) ?></h1>
        <p><?= e($typeNames[$emp['employer_type']] ?? 'Employer') ?> &middot; <?= e($emp['barangay']) ?>, Bunawan</p>
        <div class="ep-badges">
          <?php if ($isPublic): ?><span class="ep-badge ep-badge-ok"><?= icon('shield', 14) ?> <?= e(verified_label($emp['verification_status'])) ?></span><?php endif; ?>
          <?php if ($emp['is_premium']): ?><span class="ep-badge ep-badge-gold">&#9733; Premium</span><?php endif; ?>
          <?php if ($emp['peso_endorsed']): ?><span class="ep-badge">PESO endorsed</span><?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="ep-main">
  <div class="wrap">
    <?php if (!$isPublic): ?>
      <div class="alert alert-info" role="status"><b>Admin preview.</b> This employer is <?= e(strtolower(verif_info($emp['verification_status'])[0])) ?> and is not visible to the public.</div>
    <?php endif; ?>
    <div class="ep-grid">
      <div class="ep-col">
        <div class="card ep-card">
          <h2>About</h2>
          <?php if (trim((string)$emp['description']) !== ''): ?>
            <p class="ep-about"><?= nl2br(e($emp['description'])) ?></p>
          <?php else: ?>
            <p class="small">This employer has not added a description yet.</p>
          <?php endif; ?>
        </div>

        <div class="ep-jobs">
          <div class="sec-head"><div><h2>Open jobs<?= $jobs ? ' (' . count($jobs) . ')' : '' ?></h2></div></div>
          <?php if ($jobs): ?>
            <div class="grid grid-2"><?php foreach ($jobs as $j) render_job_card($j, (bool)$j['is_premium']); ?></div>
          <?php else: ?>
            <div class="card empty">
              <img src="<?= url('assets/img/empty-no-jobs.svg') ?>" alt="" width="140" onerror="this.style.display='none'">
              <h3>No open jobs right now</h3>
              <p>This employer has no active listings. Check back soon, or browse other jobs in Bunawan.</p>
              <a class="btn btn-primary" href="<?= url('jobs.php') ?>">Browse all jobs</a>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <aside class="ep-side">
        <div class="card ep-card">
          <h3>Details</h3>
          <ul class="ep-facts">
            <li><span class="ep-fi"><?= icon('building', 18) ?></span><div><small>Type</small><b><?= e($typeNames[$emp['employer_type']] ?? 'Employer') ?></b></div></li>
            <li><span class="ep-fi"><?= icon('pin', 18) ?></span><div><small>Location</small><b><?= e($emp['full_address']) ?>, <?= e($emp['barangay']) ?>, Bunawan</b><?php if ($emp['landmark']): ?><em>Near <?= e($emp['landmark']) ?></em><?php endif; ?></div></li>
          </ul>
          <?php if ($fb): ?><a class="btn btn-outline block" href="<?= e($fb) ?>" target="_blank" rel="noopener noreferrer">Visit Facebook page</a><?php endif; ?>
        </div>
        <div class="ep-safe">
          <?= icon('shield', 20) ?>
          <p><b>Never pay to get hired.</b> Verified employers on this site do not charge application, training, or placement fees. See something wrong? Use <b>Report this listing</b> on the job page.</p>
        </div>
      </aside>
    </div>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
