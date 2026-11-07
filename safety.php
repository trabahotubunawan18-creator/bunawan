<?php
$pageTitle = 'Safety Tips';
$bodyClass = 'safety-page';
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/back_button.php';
$flags = [
    ['flag',   'Asks you to pay',               'Registration, training, placement, "reservation", or starter kit fees. Real employers pay you, not the other way around.'],
    ['lock',   'Wants GCash or bank details',   'Asking for an account or wallet number "to process your application" is a common trick to take your money.'],
    ['send',   'Moves chat off the site',       'Pushes you to Telegram, WhatsApp, Viber, or a shortened link right away, where scams are harder to trace.'],
    ['shield', 'Asks for sensitive details',    'ATM card, PIN, OTP, or a selfie holding your ID. No genuine job needs these before you are hired.'],
    ['star',   'Too good to be true',           'Guaranteed income, "easy money", or a salary far above what similar local jobs pay.'],
    ['map',    'Job is not really in Bunawan',  'Overseas offers, or a listing with a vague job, no real address, and no way to visit the workplace.'],
];
?>
<section class="page-hero safety-hero">
  <div class="wrap">
    <?= back_button('index.php') ?>
    <h1>Stay safe while you look for work</h1>
    <p>Most employers here are honest, and we check them before they can post. A few habits will protect you from the rest.</p>
  </div>
</section>

<section class="sf-band">
  <div class="wrap">
    <div class="sf-rule">
      <span class="sf-rule-ico"><?= icon('shield', 28) ?></span>
      <div>
        <h2>Never pay to get hired.</h2>
        <p>Employers on Trabaho Tu Bunawan agree not to charge applicants any fee. If anyone asks for money before or after hiring you, stop and report the listing.</p>
      </div>
    </div>
  </div>
</section>

<section class="sf-band sf-band-white">
  <div class="wrap">
    <div class="sec-head"><div><h2>Red flags to watch for</h2><p class="sub">Our system watches for these too, but your eyes help.</p></div></div>
    <div class="sf-flags">
      <?php foreach ($flags as [$ic, $t, $d]): ?>
        <div class="sf-flag"><span class="sf-flag-ico"><?= icon($ic, 20) ?></span><div><h3><?= e($t) ?></h3><p><?= e($d) ?></p></div></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="sf-band">
  <div class="wrap">
    <div class="sf-two">
      <div class="card sf-card">
        <h2>Before you go to an interview</h2>
        <ul class="plan-list">
          <li>Look for the <b>Verified</b> badge, and open the employer's profile.</li>
          <li>Meet at the workplace or another public place, during the day.</li>
          <li>Tell a family member or friend where you are going and when you will be back.</li>
          <li>Bring photocopies of your documents, not the originals.</li>
          <li>Trust your gut. If something feels off, you can leave.</li>
        </ul>
      </div>
      <div class="card sf-card">
        <h2>Protect your information</h2>
        <ul class="plan-list">
          <li>Share only what the job needs. A resume does not need your ID numbers.</li>
          <li>Never give out an OTP or PIN, even to someone who says they work here.</li>
          <li>Use a password you do not use anywhere else, and log out on shared computers.</li>
          <li>Be careful with links and files sent to you by strangers.</li>
        </ul>
      </div>
    </div>
  </div>
</section>

<section class="sf-band sf-band-white">
  <div class="wrap">
    <div class="sec-head"><div><h2>If something feels wrong</h2></div></div>
    <ol class="sf-steps">
      <li><span class="num">1</span><div><h3>Stop and do not pay</h3><p>Pause the conversation. Do not send money, codes, or documents.</p></div></li>
      <li><span class="num">2</span><div><h3>Report the listing</h3><p>Open the job and choose <b>Report this listing</b>. It goes straight to our admin team, and listings with several reports are hidden for review.</p></div></li>
      <li><span class="num">3</span><div><h3>Tell us more</h3><p>Use the <a href="<?= url('contact.php') ?>">Contact page</a> if you want to share details or need help.</p></div></li>
      <li><span class="num">4</span><div><h3>Get help if you lost money</h3><p>Report it to your barangay or the local police, and tell your bank or e-wallet provider quickly.</p></div></li>
    </ol>
  </div>
</section>

<section class="sf-band">
  <div class="wrap">
    <div class="banner">
      <div><h2>Hiring? Help keep the board safe.</h2><p>Every employer is checked before posting, and every job is reviewed before it goes live. Honest job posts and no fees keep that trust.</p></div>
      <a class="btn btn-gold" href="<?= url(post_job_url()) ?>">Post a job</a>
    </div>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>