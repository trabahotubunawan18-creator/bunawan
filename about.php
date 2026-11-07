<?php
$pageTitle = 'About Us';
$bodyClass = 'about-page';
require __DIR__ . '/includes/header.php';
// Drop a real photo at assets/img/about-bunawan.jpg and it replaces the illustration automatically.
$aboutPhoto = is_file(__DIR__ . '/assets/img/about-bunawan.jpg');
?>
<section class="page-hero">
  <div class="wrap">
    <h1>About Trabaho Tu Bunawan</h1>
    <p>Where Local Talent Meets Local Opportunity.</p>
  </div>
</section>

<section class="about-band">
  <div class="wrap">
  <div class="two about-intro">
    <div class="prose">
      <h2>Why we exist</h2>
      <p>Job openings in Bunawan are often spread across Facebook posts, group chats, posters, and word of mouth. Good jobs are easy to miss, and employers struggle to reach the people who need work.</p>
      <p>Trabaho Tu Bunawan brings these vacancies into one simple, trusted place. Employers can advertise a job, and job seekers can find work close to home.</p>
    </div>
    <?php if ($aboutPhoto): ?>
      <div class="about-media">
        <figure class="about-figure">
          <img class="about-img" src="<?= url('assets/img/about-bunawan.jpg') ?>" alt="Bunawan, Agusan del Sur" loading="lazy">
        </figure>
      </div>
    <?php else: ?>
      <div class="about-media"><div class="about-art" role="img" aria-label="Illustration of Bunawan's fields and hills with a job location pin">
        <svg viewBox="0 0 480 340" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <rect width="480" height="340" fill="#E4F3E9"/>
          <circle cx="368" cy="84" r="34" fill="#F2B705"/>
          <path d="M0 210 C70 150 130 160 200 195 S340 170 480 205 V340 H0Z" fill="#A9D8B8"/>
          <path d="M0 250 C90 205 170 220 250 245 S400 215 480 245 V340 H0Z" fill="#2FB463"/>
          <path d="M0 290 C100 262 190 276 280 292 S410 270 480 288 V340 H0Z" fill="#1B7F45"/>
          <path d="M150 340 C190 300 230 285 275 262" fill="none" stroke="#E4F3E9" stroke-width="10" stroke-linecap="round" opacity=".85"/>
          <g transform="translate(222 70)">
            <path d="M18 0C-4 0-18 16-18 36c0 26 36 58 36 58s36-32 36-58C54 16 40 0 18 0z" transform="translate(0 0)" fill="#0F3D2E"/>
            <rect x="2" y="26" width="32" height="22" rx="3" fill="none" stroke="#fff" stroke-width="3"/>
            <path d="M12 26v-4a3 3 0 0 1 3-3h6a3 3 0 0 1 3 3v4" fill="none" stroke="#fff" stroke-width="3"/>
          </g>
          <ellipse cx="240" cy="172" rx="22" ry="5" fill="#0F3D2E" opacity=".18"/>
        </svg>
      </div></div>
    <?php endif; ?>
  </div>
  </div>
</section>

<section class="about-band">
  <div class="wrap">
  <div class="sec-head"><div><h2>Who it is for</h2></div></div>
  <div class="grid grid-3">
    <div class="card how">
      <span class="how-ico"><?= icon('user', 24) ?></span>
      <h3>Job seekers</h3>
      <p>Students looking for part-time work, fresh graduates, and anyone seeking full-time or part-time jobs in Bunawan.</p>
    </div>
    <div class="card how">
      <span class="how-ico"><?= icon('building', 24) ?></span>
      <h3>Local employers</h3>
      <p>Businesses, farms, contractors, organizations, and households that need workers.</p>
    </div>
    <div class="card how how-safe">
      <span class="how-ico"><?= icon('home', 24) ?></span>
      <h3>The community</h3>
      <p>Keeping jobs, income, and talent inside Bunawan, Agusan del Sur.</p>
    </div>
  </div>
  </div>
</section>

<section class="about-band">
  <div class="wrap">
  <div class="about-panel">
  <div class="sec-head"><div><h2>What makes it different</h2></div></div>
  <div class="diff-grid">
    <div class="diff">
      <span class="how-ico"><?= icon('pin', 22) ?></span>
      <div><h3>Local only</h3><p>Every job is in Bunawan, Agusan del Sur.</p></div>
    </div>
    <div class="diff">
      <span class="how-ico"><?= icon('shield', 22) ?></span>
      <div><h3>Verified employers</h3><p>Our admin team reviews each employer before they can post, and every listing is approved before it goes live.</p></div>
    </div>
    <div class="diff">
      <span class="how-ico"><?= icon('send', 22) ?></span>
      <div><h3>Simple applications</h3><p>Apply with a short form. A resume is needed only when the employer asks for one.</p></div>
    </div>
    <div class="diff">
      <span class="how-ico"><?= icon('tag', 22) ?></span>
      <div><h3>Free for job seekers</h3><p>Employers post for free, with an optional Premium plan for more visibility.</p></div>
    </div>
    <div class="diff diff-wide">
      <span class="how-ico"><?= icon('flag', 22) ?></span>
      <div><h3>Safety first</h3><p>Report any listing that looks like a scam, and remember: never pay to get hired. <a href="<?= url('safety.php') ?>">Read safety tips</a></p></div>
    </div>
  </div>
  </div>
  </div>
</section>

<section class="about-band">
  <div class="wrap">
  <div class="banner">
    <div><h2>Ready to get started?</h2><p>Find your next job, or post a vacancy and meet local talent.</p></div>
    <div style="display:flex;gap:12px;flex-wrap:wrap">
      <a class="btn btn-white" href="<?= url('jobs.php') ?>">Find Jobs</a>
      <a class="btn btn-gold" href="<?= url(post_job_url()) ?>">Post a Job</a>
    </div>
  </div>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>