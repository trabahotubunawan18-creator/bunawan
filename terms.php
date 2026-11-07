<?php
// Plain-language Terms of Use. Have it reviewed by a lawyer before a public launch.
$pageTitle = 'Terms of Use';
$bodyClass = 'doc-page';
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/back_button.php';
?>
<section class="page-hero doc-hero">
  <div class="wrap">
    <?= back_button('index.php') ?>
    <h1>Terms of Use</h1>
    <p>The simple rules for using Trabaho Tu Bunawan, for job seekers and employers.</p>
    <span class="doc-meta">Last updated: October 2026</span>
  </div>
</section>

<section class="doc-band">
  <div class="wrap doc-layout">
    <nav class="doc-toc" aria-label="On this page">
      <b>On this page</b>
      <ol>
        <li><a href="#using">Using the site</a></li>
        <li><a href="#accounts">Your account</a></li>
        <li><a href="#seekers">For job seekers</a></li>
        <li><a href="#employers">For employers</a></li>
        <li><a href="#premium">Premium</a></li>
        <li><a href="#rules">What is not allowed</a></li>
        <li><a href="#moderation">Reports and moderation</a></li>
        <li><a href="#content">Your content</a></li>
        <li><a href="#liability">Limits of our role</a></li>
        <li><a href="#general">General terms</a></li>
      </ol>
    </nav>

    <article class="doc">
      <p class="doc-intro">By creating an account or using Trabaho Tu Bunawan, you agree to these terms and to our <a href="<?= url('privacy.php') ?>">Privacy Policy</a>. If you do not agree, please do not use the site.</p>

      <section id="using">
        <h2>Using the site</h2>
        <p>Trabaho Tu Bunawan is a free job board for Bunawan, Agusan del Sur. We help job seekers and employers find each other. Anyone can browse jobs. You need an account to apply, save jobs, or post vacancies.</p>
      </section>

      <section id="accounts">
        <h2>Your account</h2>
        <ul>
          <li>Give true and current information, and keep it up to date.</li>
          <li>Keep your password private. You are responsible for what happens under your account.</li>
          <li>Use one account per person or employer.</li>
          <li>Tell us right away if you think someone else has used your account.</li>
        </ul>
      </section>

      <section id="seekers">
        <h2>For job seekers</h2>
        <ul>
          <li>Apply only for jobs you are really interested in, and keep your details and resume honest.</li>
          <li>Applying does not guarantee an interview or a job. The employer makes that decision, not us.</li>
          <li>You should never have to pay to apply or to be hired. If someone asks, <a href="<?= url('safety.php') ?>">read our safety tips</a> and report the listing.</li>
        </ul>
      </section>

      <section id="employers">
        <h2>For employers</h2>
        <ul>
          <li>Every employer is checked before posting. We may ask for documents, call your contact number, or call your references. We can approve, decline, or ask for more information.</li>
          <li>Each job post is reviewed before it goes live. We may hold or remove posts that look misleading or unsafe.</li>
          <li>Job posts must be for work in Bunawan and must describe the job truthfully, including pay if you state it.</li>
          <li><b>You must never charge applicants</b> any placement, training, application, or similar fee, or ask them to send money.</li>
          <li>Accounts have a limit on how many job posts can be active at once.</li>
          <li>Treat applicants' information with care and use it only to consider them for the job.</li>
        </ul>
      </section>

      <section id="premium">
        <h2>Premium</h2>
        <p>Premium gives employers more visibility. Premium costs ₱49 per month for each job post you choose. Payment is online only (GCash, Maya, or bank transfer). The site does not take the payment itself: you choose a method, send your request, and our admin team switches it on after confirming your payment. If paid plans begin, we will update these terms first and tell you before you are charged anything.</p>
      </section>

      <section id="rules">
        <h2>What is not allowed</h2>
        <ul>
          <li>Scams, fake jobs, or any listing that asks for money, bank or e-wallet details, ATM cards, PINs, or one-time passwords.</li>
          <li>Recruiting for jobs abroad, or work that is illegal or unsafe.</li>
          <li>Pretending to be another person, business, or organization.</li>
          <li>Harassing, threatening, or discriminating against others.</li>
          <li>Uploading harmful files, or trying to break, overload, or get around the security of the site.</li>
          <li>Collecting other people's information from the site for any other purpose.</li>
        </ul>
      </section>

      <section id="moderation">
        <h2>Reports and moderation</h2>
        <p>Anyone can report a listing that looks wrong. Listings that receive several reports may be hidden until our admin team reviews them. We may remove content, warn or suspend accounts, or refuse registration when these terms are broken or when we believe people may be at risk. If you think we made a mistake, tell us through the <a href="<?= url('contact.php') ?>">Contact page</a> and we will take another look.</p>
      </section>

      <section id="content">
        <h2>Your content</h2>
        <p>You keep ownership of what you submit, such as job posts, logos, and resumes. By submitting it, you allow us to store it and show it on the site for the purposes described in the Privacy Policy. Please submit only things you have the right to share.</p>
      </section>

      <section id="liability">
        <h2>Limits of our role</h2>
        <p>We are not the employer or the recruiter. We do our best to verify employers, but we cannot promise that every listing, employer, or applicant is trustworthy or that a job will be available, so please use your own judgment and follow our <a href="<?= url('safety.php') ?>">safety tips</a>. The site is provided as is, and to the extent allowed by law we are not responsible for losses that come from dealings between users. Nothing here limits rights you have under Philippine law.</p>
      </section>

      <section id="general">
        <h2>General terms</h2>
        <ul>
          <li>You can stop using the site and ask us to delete your account at any time.</li>
          <li>We may suspend or close accounts that break these terms.</li>
          <li>We may update these terms. When we do, we will change the date at the top. Using the site after that means you accept the update.</li>
          <li>These terms are governed by the laws of the Republic of the Philippines.</li>
        </ul>
        <p>Questions? Reach us on the <a href="<?= url('contact.php') ?>">Contact page</a>.</p>
      </section>
    </article>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>