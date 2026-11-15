<?php
// Job seeker User Guide, shown inside the job seeker dashboard.
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require_role('seeker');
$pageTitle = 'User Guide';
require __DIR__ . '/../includes/dash_top.php';
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
  <p class="dash-sub">How to find and apply for jobs on Trabaho Tu Bunawan.</p>

  <div class="ug-grid">
    <div class="ug-card"><span class="ug-num">1</span><h3>Complete your profile</h3>
      <p>Add your mobile number, barangay, skills and experience so employers can reach you. We use your skills to recommend jobs that fit you.</p>
      <a class="go" href="<?= url('seeker/profile.php') ?>">Open My profile</a></div>
    <div class="ug-card"><span class="ug-num">2</span><h3>Find jobs</h3>
      <p>Search by keyword, barangay, skills or category. Tap the bookmark to save a job and look at it later.</p>
      <a class="go" href="<?= url('seeker/browse.php') ?>">Browse jobs</a> &middot; <a class="go" href="<?= url('seeker/saved.php') ?>">Saved jobs</a></div>
    <div class="ug-card"><span class="ug-num">3</span><h3>Apply</h3>
      <p>Apply with a short form. A resume is optional, so add one only if an employer asks for it.</p>
      <a class="go" href="<?= url('seeker/resume.php') ?>">Open Resume</a></div>
    <div class="ug-card"><span class="ug-num">4</span><h3>Track your applications</h3>
      <p>Follow each one from Pending to Reviewed, Shortlisted and Accepted. Employer contact details appear once you are shortlisted. Notifications tell you about every update.</p>
      <a class="go" href="<?= url('seeker/applications.php') ?>">My applications</a></div>
  </div>

  <div class="ug-note"><?= icon('shield', 22) ?><div><b>Stay safe.</b> Employers here agree not to charge applicants any fee, and every employer is checked by our admin team. Read our <a href="<?= url('safety.php') ?>" target="_blank" rel="noopener">Safety Tips</a>, or see the <a href="<?= url('faq.php') ?>" target="_blank" rel="noopener">FAQ</a> for more answers.</div></div>
</div>
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>
