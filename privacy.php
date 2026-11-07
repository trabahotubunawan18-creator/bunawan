<?php
// Plain-language Privacy Policy. Have it reviewed by a lawyer or your Data Protection Officer before a public launch.
$pageTitle = 'Privacy Policy';
$bodyClass = 'doc-page';
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/back_button.php';
?>
<section class="page-hero doc-hero">
  <div class="wrap">
    <?= back_button('index.php') ?>
    <h1>Privacy Policy</h1>
    <p>What we collect, why we collect it, and how we look after it.</p>
    <span class="doc-meta">Last updated: October 2026</span>
  </div>
</section>

<section class="doc-band">
  <div class="wrap doc-layout">
    <nav class="doc-toc" aria-label="On this page">
      <b>On this page</b>
      <ol>
        <li><a href="#who">Who we are</a></li>
        <li><a href="#collect">What we collect</a></li>
        <li><a href="#use">How we use it</a></li>
        <li><a href="#share">Who can see it</a></li>
        <li><a href="#keep">How long we keep it</a></li>
        <li><a href="#secure">How we protect it</a></li>
        <li><a href="#cookies">Cookies and third parties</a></li>
        <li><a href="#rights">Your rights</a></li>
        <li><a href="#changes">Changes and contact</a></li>
      </ol>
    </nav>

    <article class="doc">
      <p class="doc-intro">Trabaho Tu Bunawan helps people in Bunawan, Agusan del Sur find local work and helps local employers find workers. We follow the Data Privacy Act of 2012 (Republic Act 10173). This page explains our practices in plain language.</p>

      <section id="who">
        <h2>Who we are</h2>
        <p>Trabaho Tu Bunawan is a local job platform for Bunawan, Agusan del Sur, Philippines. We decide what personal information is collected through this site and why. You can reach us any time through the <a href="<?= url('contact.php') ?>">Contact page</a>.</p>
      </section>

      <section id="collect">
        <h2>What we collect</h2>
        <h3>When you register as a job seeker</h3>
        <ul>
          <li>Your name, email address, and mobile number.</li>
          <li>Your password. We store only a scrambled version (a hash) that nobody can read back.</li>
          <li>Your profile and resume, if you add them, and the jobs you save or apply for, including the message you send with an application.</li>
        </ul>
        <h3>When you register as an employer</h3>
        <ul>
          <li>Your name, email address, and mobile number, and your business, organization, or household name, address, description, and Facebook page.</li>
          <li>Verification details: permit or registration numbers, DTI or SEC number, a photo or scan of your Business Permit or Barangay Certification, a photo of your workplace, a photo of a valid government-issued ID of the contact person, and a selfie of that person holding the ID.</li>
          <li>The names, phone numbers, and relationship of your two local references.</li>
          <li>The job posts you write.</li>
        </ul>
        <h3>From everyone</h3>
        <ul>
          <li>Your IP address and the time of certain actions, such as logging in or sending a message. We use this to limit repeated attempts and stop abuse.</li>
          <li>Messages you send through the Contact form and reports you file about a listing.</li>
        </ul>
        <p>If you give us information about another person, such as a reference, please make sure they are okay with it.</p>
      </section>

      <section id="use">
        <h2>How we use it</h2>
        <ul>
          <li>To create and run your account.</li>
          <li>To connect job seekers with employers when you apply.</li>
          <li>To verify employers, so job seekers can trust the listings they see.</li>
          <li>To review reports, spot scams, and keep the site safe.</li>
          <li>To contact you about your account, applications, or a report.</li>
        </ul>
        <p>We collect only what these purposes need, and we rely on your consent, which you give when you register and tick the agreement.</p>
      </section>

      <section id="share">
        <h2>Who can see it</h2>
        <div class="doc-table" role="table" aria-label="Who can see what">
          <div class="doc-row doc-row-head" role="row"><span role="columnheader">Information</span><span role="columnheader">Who can see it</span></div>
          <div class="doc-row" role="row"><span role="cell">Your application, message, and resume</span><span role="cell">The employer you applied to, and our admin team</span></div>
          <div class="doc-row" role="row"><span role="cell">Employer public profile: name, type, barangay, description, verified badge, and open jobs</span><span role="cell">Anyone visiting the site</span></div>
          <div class="doc-row" role="row"><span role="cell">Employer contact name and mobile number</span><span role="cell">Our admin team, and any job seeker the employer shortlists or accepts</span></div>
          <div class="doc-row" role="row"><span role="cell">Verification documents, ID photos, permit numbers, and references</span><span role="cell">Our admin team only</span></div>
          <div class="doc-row" role="row"><span role="cell">Your email, password, and account details</span><span role="cell">You. Admins can see your account details to manage the site, but never your password.</span></div>
        </div>
        <p>Our admin team may call an employer's references or contact number as part of verification. We do not sell or rent your personal information. We share it with others only if the law requires it, or to protect people from fraud or harm.</p>
      </section>

      <section id="keep">
        <h2>How long we keep it</h2>
        <p>We keep your information while your account is active and for as long as we need it for verification and safety records, for example to stop a banned employer from simply registering again. ID photos and selfies are kept only as long as we need them to verify and keep your listing verified, and are deleted when you ask us to or when they are no longer needed. You can ask us to delete your account and information at any time (see <a href="#rights">Your rights</a>).</p>
      </section>

      <section id="secure">
        <h2>How we protect it</h2>
        <ul>
          <li>Passwords are stored as hashes, never as plain text.</li>
          <li>Verification documents, including ID photos and selfies, are stored in a private folder that cannot be opened with a web address. Only our admin team can view them, and we use them only to confirm who is behind an employer account.</li>
          <li>We limit repeated login attempts and temporarily lock an account after several wrong passwords.</li>
          <li>You are logged out automatically after 30 minutes of inactivity.</li>
        </ul>
        <p>No website is perfectly secure. Please choose a strong password, do not share it, and log out on shared computers.</p>
      </section>

      <section id="cookies">
        <h2>Cookies and third parties</h2>
        <p>We use one essential cookie to keep you logged in and to protect forms from forged requests. We do not use advertising or tracking cookies.</p>
        <p>Some pages load things from other services, and your browser contacts them directly when that happens: fonts from Google Fonts, map tiles on job pages, and, only if you click it, the Facebook share link. Those services have their own privacy policies.</p>
      </section>

      <section id="rights">
        <h2>Your rights</h2>
        <p>Under the Data Privacy Act, you have the right to:</p>
        <ul>
          <li>be informed about how your data is used (this page);</li>
          <li>see the personal information we hold about you;</li>
          <li>correct information that is wrong;</li>
          <li>object to certain uses, or withdraw your consent;</li>
          <li>ask us to delete or block your information;</li>
          <li>receive a copy of your information; and</li>
          <li>claim damages if your data is misused.</li>
        </ul>
        <p>To use any of these rights, send us a message through the <a href="<?= url('contact.php') ?>">Contact page</a> and tell us which account it is about. You may also file a complaint with the National Privacy Commission at <a href="https://privacy.gov.ph" target="_blank" rel="noopener noreferrer">privacy.gov.ph</a>.</p>
        <p>This site is meant for people who are allowed to work. If you are under 18, please ask a parent or guardian before sharing your details.</p>
      </section>

      <section id="changes">
        <h2>Changes and contact</h2>
        <p>If we change this policy in a meaningful way, we will update the date at the top of this page. Questions are always welcome through the <a href="<?= url('contact.php') ?>">Contact page</a>.</p>
      </section>
    </article>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>