<?php
// Job seeker User Guide, shown inside the job seeker dashboard. Styles: "USER GUIDE" block in assets/css/style.css.
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require_role('seeker');
$pageTitle = 'User Guide';
require __DIR__ . '/../includes/dash_top.php';
?>
<div class="ug">
  <header class="ug-hero">
    <div class="ug-hero-text">
      <span class="ug-eyebrow"><?= icon('user', 14) ?> Job seeker</span>
      <h1 class="dash-title ug-title">User Guide</h1>
      <p class="ug-sub">How to find and apply for jobs on Trabaho Tu Bunawan, step by step.</p>
    </div>
    <span class="ug-hero-ico"><?= icon('search', 150) ?></span>
  </header>

  <h2 class="ug-h">Find a job in 4 steps</h2>
  <section class="ug-steps">
    <article class="ug-step">
      <div class="ug-step-top"><span class="ug-num">1</span><span class="ug-ico"><?= icon('user', 22) ?></span></div>
      <h3>Complete your profile</h3>
      <p>Add your mobile number, barangay, skills and experience so employers can reach you. We use your skills to recommend jobs that fit you.</p>
      <div class="ug-links"><a class="ug-link" href="<?= url('seeker/profile.php') ?>">Open My profile <?= icon('arrow', 15) ?></a></div>
    </article>
    <article class="ug-step">
      <div class="ug-step-top"><span class="ug-num">2</span><span class="ug-ico"><?= icon('search', 22) ?></span></div>
      <h3>Find jobs</h3>
      <p>Search by keyword, barangay, skills or category. Tap the bookmark to save a job and look at it later.</p>
      <div class="ug-links"><a class="ug-link" href="<?= url('seeker/browse.php') ?>">Browse jobs <?= icon('arrow', 15) ?></a> <a class="ug-link" href="<?= url('seeker/saved.php') ?>">Saved jobs <?= icon('arrow', 15) ?></a></div>
    </article>
    <article class="ug-step">
      <div class="ug-step-top"><span class="ug-num">3</span><span class="ug-ico"><?= icon('send', 22) ?></span></div>
      <h3>Apply</h3>
      <p>Apply with a short form. A resume is optional, so add one only if an employer asks for it.</p>
      <div class="ug-links"><a class="ug-link" href="<?= url('seeker/resume.php') ?>">Open Resume <?= icon('arrow', 15) ?></a></div>
    </article>
    <article class="ug-step">
      <div class="ug-step-top"><span class="ug-num">4</span><span class="ug-ico"><?= icon('clock', 22) ?></span></div>
      <h3>Track your applications</h3>
      <p>Follow every application and get a notification each time an employer updates it.</p>
      <div class="ug-links"><a class="ug-link" href="<?= url('seeker/applications.php') ?>">My applications <?= icon('arrow', 15) ?></a> <a class="ug-link" href="<?= url('seeker/notifications.php') ?>">Notifications <?= icon('arrow', 15) ?></a></div>
    </article>
  </section>

  <section class="ug-panel">
    <h3>How an application moves</h3>
    <p>Each application goes through these stages. Employer contact details appear once you are shortlisted.</p>
    <ol class="ug-path">
      <li><b>Pending</b>Sent and waiting for the employer.</li>
      <li><b>Reviewed</b>The employer has looked at it.</li>
      <li><b>Shortlisted</b>You are a top choice. Contact details unlock.</li>
      <li><b>Accepted</b>You got the job.</li>
    </ol>
  </section>

  <aside class="ug-note"><?= icon('shield', 22) ?><div><b>Stay safe.</b> Employers here agree not to charge applicants any fee, and every employer is checked by our admin team. Read our <a href="<?= url('safety.php') ?>" target="_blank" rel="noopener">Safety Tips</a>, or see the <a href="<?= url('faq.php') ?>" target="_blank" rel="noopener">FAQ</a> for more answers.</div></aside>
</div>
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>
