<?php
$pageTitle = 'FAQ';
$bodyClass = 'faq-page';
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/back_button.php';

$faq = [
  'Job seekers' => [
    ['Is it free to use?', 'Yes. Browsing, applying, and saving jobs are free for job seekers. You should never pay anyone to apply or to be hired.'],
    ['Do I need an account to apply?', 'Yes. Create a free job seeker account, which takes about a minute. It lets you track every application and save jobs for later.'],
    ['Do I need a resume?', 'Only when the employer asks for one. Each job shows whether a resume is required, optional, or not needed. Look for the "No resume needed" tag.'],
    ['How do I know the status of my application?', 'Log in and open My Applications. The employer updates it as they review you, for example Reviewed, Shortlisted, or Accepted.'],
    ['Can students or weekend workers find jobs here?', 'Yes. Use the Student-friendly filter and the Work schedule filter (weekdays, weekends, or flexible hours) on the Find Jobs page.'],
  ],
  'Employers' => [
    ['Who can post a job?', 'Registered businesses, organizations such as cooperatives and schools, and individuals or households in Bunawan, for example farm owners or families hiring a helper.'],
    ['Why do I have to be verified?', 'Verification keeps job seekers safe and makes your listings trusted. We review your details and documents, and we may call your contact number or references.'],
    ['What do I need to register?', 'Your address in Bunawan, a contact person and mobile number, a photo of your workplace, and two local references. Registered businesses add a Business Permit, and individuals add a Barangay Certification. <a href="' . url('register.php?role=employer') . '">See the full form</a>.'],
    ['How long until I can post?', 'You can post once our admin team approves your verification. You will see the result in your employer dashboard. If we need more information, it will say so there.'],
    ['Is my job posted right away?', 'Not instantly. Each post is checked before it goes live, which helps keep scams off the board.'],
    ['Is there a limit on posts? What does Premium do?', 'Every account has a limit on how many posts can be active at once. Premium is ₱49 per month for each post you choose. A Premium post is featured on the home page, shows a gold badge, and ranks higher in search. Payment is online only (GCash, Maya, or bank transfer). The site does not take the payment itself: you pick a method, send your request, and our admin activates it after receiving your payment.'],
  ],
  'Safety' => [
    ['What if a job asks me to pay a fee?', 'Do not pay. Employers here agree never to charge applicants. Report the listing using Report this listing on the job page. <a href="' . url('safety.php') . '">Read our safety tips</a>.'],
    ['How do I report a suspicious job?', 'Open the job, choose Report this listing, and tell us what looks wrong. Listings with several reports are hidden until our team reviews them.'],
    ['What does the Verified badge mean?', 'Our admin team checked the employer\'s details and documents. It is a good sign, but you should still follow the safety tips.'],
  ],
  'Your account' => [
    ['I forgot my password. What do I do?', 'Use <a href="' . url('forgot-password.php') . '">Forgot your password</a> on the login page. We will send a link to choose a new one, and it works for 60 minutes.'],
    ['Why was my account locked?', 'After five wrong passwords in a row, an account is locked for 15 minutes to protect it. Wait, then try again, or reset your password.'],
    ['Why was I logged out?', 'For your safety, you are logged out after 30 minutes without activity.'],
    ['How do I delete my account or my data?', 'Send us a message on the <a href="' . url('contact.php') . '">Contact page</a> and tell us which account it is. See our <a href="' . url('privacy.php') . '">Privacy Policy</a> for your rights.'],
  ],
];
?>
<section class="page-hero faq-hero">
  <div class="wrap">
    <?= back_button('index.php') ?>
    <h1>Frequently asked questions</h1>
    <p>Quick answers for job seekers and employers in Bunawan.</p>
  </div>
</section>

<section class="faq-band">
  <div class="wrap faq-wrap">
    <nav class="faq-jump" aria-label="Question groups">
      <?php foreach (array_keys($faq) as $i => $g): ?><a href="#g<?= $i ?>"><?= e($g) ?></a><?php endforeach; ?>
    </nav>

    <?php foreach ($faq as $group => $items): $gi = array_search($group, array_keys($faq)); ?>
      <section class="faq-group" id="g<?= $gi ?>">
        <h2><?= e($group) ?></h2>
        <div class="card faq-list">
          <?php foreach ($items as [$q, $a]): ?>
            <details class="faq-item">
              <summary><?= e($q) ?></summary>
              <p><?= $a /* trusted text and links written above */ ?></p>
            </details>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>

    <div class="faq-more">
      <div><h3>Did not find your answer?</h3><p>Send us a message and we will help.</p></div>
      <a class="btn btn-primary" href="<?= url('contact.php') ?>">Contact us</a>
    </div>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>