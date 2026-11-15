<?php
// Employer User Guide, shown inside the employer dashboard. Styles: "USER GUIDE" block in assets/css/style.css.
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require_role('employer');
$pageTitle = 'User Guide';
require __DIR__ . '/../includes/emp_top.php';
?>
<div class="ug">
  <header class="ug-hero">
    <div class="ug-hero-text">
      <span class="ug-eyebrow"><?= icon('building', 14) ?> Employer</span>
      <h1 class="dash-title ug-title">User Guide</h1>
      <p class="ug-sub">How to get verified, post jobs and hire local talent on Trabaho Tu Bunawan.</p>
    </div>
    <span class="ug-hero-ico"><?= icon('briefcase', 150) ?></span>
  </header>

  <?php if ($canPost): ?>
    <div class="ug-status ug-status--ok"><span class="ug-status-ico"><?= icon('check', 20) ?></span><div><b>Your account is verified</b><p>You can post jobs and review applicants.</p></div></div>
  <?php else: ?>
    <div class="ug-status"><span class="ug-status-ico"><?= icon('shield', 20) ?></span><div><b>Your account status: <?= e($vLabel) ?></b><p>You can post jobs once the admin approves your verification. <a href="<?= url('employer/verification.php') ?>">Check your verification</a>.</p></div></div>
  <?php endif; ?>

  <h2 class="ug-h">Hire in 4 steps</h2>
  <section class="ug-steps">
    <article class="ug-step">
      <div class="ug-step-top"><span class="ug-num">1</span><span class="ug-ico"><?= icon('building', 22) ?></span></div>
      <h3>Complete your company profile</h3>
      <p>Tell job seekers who you are: your name, address, contact person and photos of your workplace.</p>
      <div class="ug-links"><a class="ug-link" href="<?= url('employer/company-profile.php') ?>">Company profile <?= icon('arrow', 15) ?></a></div>
    </article>
    <article class="ug-step">
      <div class="ug-step-top"><span class="ug-num">2</span><span class="ug-ico"><?= icon('shield', 22) ?></span></div>
      <h3>Get verified</h3>
      <p>Our admin reviews your details, documents and references. This is what earns your verified badge.</p>
      <div class="ug-links"><a class="ug-link" href="<?= url('employer/verification.php') ?>">Verification <?= icon('arrow', 15) ?></a></div>
    </article>
    <article class="ug-step">
      <div class="ug-step-top"><span class="ug-num">3</span><span class="ug-ico"><?= icon('plus', 22) ?></span></div>
      <h3>Post a vacancy</h3>
      <p>Describe the job, set a deadline and choose whether a resume is needed. Each post is checked before it goes live.</p>
      <div class="ug-links"><a class="ug-link" href="<?= url('employer/post-job.php') ?>">Post a job <?= icon('arrow', 15) ?></a> <a class="ug-link" href="<?= url('employer/my-jobs.php') ?>">My job posts <?= icon('arrow', 15) ?></a></div>
    </article>
    <article class="ug-step">
      <div class="ug-step-top"><span class="ug-num">4</span><span class="ug-ico"><?= icon('users', 22) ?></span></div>
      <h3>Review applicants</h3>
      <p>See who applied, update their status and contact them directly.</p>
      <div class="ug-links"><a class="ug-link" href="<?= url('employer/applicants.php') ?>">Applicants <?= icon('arrow', 15) ?></a></div>
    </article>
  </section>

  <h2 class="ug-h">What we ask for</h2>
  <section class="ug-panels">
    <div class="ug-panel">
      <div class="ug-panel-head"><h3>For everyone</h3></div>
      <ul class="ug-list">
        <li>Your complete Bunawan address with barangay and landmark, a contact person and mobile number, and a Facebook page if you have one.</li>
        <li><b>Workplace photos:</b> the storefront, signage, or farm or work area.</li>
        <li><b>Two local references:</b> name, number, and relationship (for example a barangay official, supplier, or employee).</li>
        <li><b>Agreements:</b> no fees charged to applicants, honest job posts, and consent under the Data Privacy Act (RA 10173).</li>
      </ul>
    </div>
    <div class="ug-panel">
      <div class="ug-panel-head"><h3>Depending on who you are</h3></div>
      <ul class="ug-list">
        <li><b>Registered businesses:</b> Business Permit number and expiry date, plus DTI or SEC registration if available.</li>
        <li><b>Individuals and households:</b> a Barangay Certification.</li>
        <li><b>Organizations:</b> your registration or accreditation number.</li>
      </ul>
      <p class="ug-foot"><?= icon('lock', 16) ?> Your documents are stored privately and are only seen by our admin team.</p>
    </div>
  </section>

  <h2 class="ug-h">Free and Premium</h2>
  <section class="ug-panels">
    <div class="ug-panel">
      <div class="ug-panel-head"><h3>Free</h3></div>
      <p class="ug-price">&#8369;0</p>
      <ul class="ug-list"><li>Verified employer badge</li><li>Post jobs (limited active posts)</li><li>Applicant management</li><li>Basic statistics</li></ul>
    </div>
    <div class="ug-panel ug-panel--premium">
      <div class="ug-panel-head"><h3>Premium</h3><span class="ug-tag"><?= icon('star', 13) ?> Featured</span></div>
      <p class="ug-price">&#8369;49 <small>per month for each boosted post</small></p>
      <ul class="ug-list"><li>Everything in Free</li><li>Featured on the home page</li><li>Gold Premium badge</li><li>Higher ranking in search</li><li>Pick which posts to boost</li></ul>
      <p class="ug-fine">Pay online (GCash, Maya, or bank transfer): choose a method, send your request, and the admin activates it after receiving your payment.</p>
      <a class="btn btn-primary btn-sm" href="<?= url('employer/premium.php') ?>">Open Premium</a>
    </div>
  </section>
</div>
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>
