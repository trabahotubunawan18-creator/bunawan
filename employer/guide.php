<?php
// Employer User Guide, shown inside the employer dashboard.
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require_role('employer');
$pageTitle = 'User Guide';
require __DIR__ . '/../includes/emp_top.php';
?>
<style>
.ug{max-width:1000px}
.ug-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:16px;margin:0 0 24px}
.ug-card{background:#fff;border:1px solid var(--line,#E1E9E4);border-radius:14px;padding:20px}
.ug-num{width:34px;height:34px;border-radius:50%;background:var(--green,#1B7F45);color:#fff;font-weight:700;display:flex;align-items:center;justify-content:center;margin-bottom:12px}
.ug-card h3{margin:0 0 6px;font-size:16px}
.ug-card p{margin:0 0 10px;color:var(--muted,#52665A);font-size:14.5px;line-height:1.55}
.ug-card ul{margin:0 0 10px;padding-left:18px;color:var(--muted,#52665A);font-size:14.5px;line-height:1.6}
.ug-card .go{font-weight:600;font-size:14px}
.ug-h{font-size:18px;margin:26px 0 12px}
.ug-note{display:flex;gap:12px;align-items:flex-start;background:#FFF8E1;border-left:4px solid #F2B705;border-radius:10px;padding:14px 18px;margin:0 0 24px;line-height:1.6}
.ug-ok{background:#EEF7F1;border-left-color:#1B7F45}
.ug-two{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;margin:0 0 24px}
</style>
<div class="ug">
  <h1 class="dash-title">User Guide</h1>
  <p class="dash-sub">How to get verified, post jobs and hire on Trabaho Tu Bunawan.</p>

  <?php if ($canPost): ?>
    <div class="ug-note ug-ok"><?= icon('check', 22) ?><div><b>Your account is verified.</b> You can post jobs and review applicants.</div></div>
  <?php else: ?>
    <div class="ug-note"><?= icon('shield', 22) ?><div><b>Your account status: <?= e($vLabel) ?>.</b> You can post jobs once the admin approves your verification. <a href="<?= url('employer/verification.php') ?>">Check your verification</a>.</div></div>
  <?php endif; ?>

  <div class="ug-grid">
    <div class="ug-card"><span class="ug-num">1</span><h3>Complete your company profile</h3>
      <p>Tell job seekers who you are: your name, address, contact person and photos of your workplace.</p>
      <a class="go" href="<?= url('employer/company-profile.php') ?>">Company profile</a></div>
    <div class="ug-card"><span class="ug-num">2</span><h3>Get verified</h3>
      <p>Our admin reviews your details, documents and references. This is what earns your verified badge.</p>
      <a class="go" href="<?= url('employer/verification.php') ?>">Verification</a></div>
    <div class="ug-card"><span class="ug-num">3</span><h3>Post a vacancy</h3>
      <p>Describe the job, set a deadline and choose whether a resume is needed. Each post is checked before it goes live.</p>
      <a class="go" href="<?= url('employer/post-job.php') ?>">Post a job</a> &middot; <a class="go" href="<?= url('employer/my-jobs.php') ?>">My job posts</a></div>
    <div class="ug-card"><span class="ug-num">4</span><h3>Review applicants</h3>
      <p>See who applied, update their status and contact them directly.</p>
      <a class="go" href="<?= url('employer/applicants.php') ?>">Applicants</a></div>
  </div>

  <h2 class="ug-h">What we ask for</h2>
  <div class="ug-two">
    <div class="ug-card"><h3>For everyone</h3>
      <ul>
        <li>Your complete Bunawan address with barangay and landmark, a contact person and mobile number, and a Facebook page if you have one.</li>
        <li><b>Workplace photos:</b> the storefront, signage, or farm or work area.</li>
        <li><b>Two local references:</b> name, number, and relationship (for example a barangay official, supplier, or employee).</li>
        <li><b>Agreements:</b> no fees charged to applicants, honest job posts, and consent under the Data Privacy Act (RA 10173).</li>
      </ul></div>
    <div class="ug-card"><h3>Depending on who you are</h3>
      <ul>
        <li><b>Registered businesses:</b> Business Permit number and expiry date, plus DTI or SEC registration if available.</li>
        <li><b>Individuals and households:</b> a Barangay Certification.</li>
        <li><b>Organizations:</b> your registration or accreditation number.</li>
      </ul>
      <p><?= icon('lock', 15) ?> Your documents are stored privately and are only seen by our admin team.</p></div>
  </div>

  <h2 class="ug-h">Free and Premium</h2>
  <div class="ug-two">
    <div class="ug-card"><h3>Free</h3>
      <ul><li>Verified employer badge</li><li>Post jobs (limited active posts)</li><li>Applicant management</li><li>Basic statistics</li></ul></div>
    <div class="ug-card" style="border:2px solid #F2B705"><h3>Premium &middot; &#8369;49 per month for each boosted post</h3>
      <ul><li>Everything in Free</li><li>Featured on the home page</li><li>Gold Premium badge</li><li>Higher ranking in search</li><li>Pick which posts to boost</li></ul>
      <p>Pay online (GCash, Maya, or bank transfer): choose a method, send your request, and the admin activates it after receiving your payment.</p>
      <a class="go" href="<?= url('employer/premium.php') ?>">Open Premium</a></div>
  </div>
</div>
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>
