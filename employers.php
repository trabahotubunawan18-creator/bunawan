<?php
$pageTitle = 'For Employers';
$bodyClass = 'emp-page';
require __DIR__ . '/includes/header.php';
$postUrl = url(post_job_url());
?>
<section class="page-hero">
  <div class="wrap">
    <h1>Need a worker in Bunawan?</h1>
    <p>Post your vacancy for free and reach local job seekers in one place. Stores, farms, contractors, offices, and households are all welcome.</p>
    <div class="emp-hero-chips">
      <span class="chip"><?= icon('check', 15) ?> Free to post</span>
      <span class="chip"><?= icon('shield', 15) ?> Verified employers</span>
      <span class="chip"><?= icon('pin', 15) ?> Local job seekers</span>
    </div>
    <div class="emp-hero-actions">
      <a class="btn btn-gold" href="<?= $postUrl ?>">Post a Job</a>
      <a class="btn btn-white" href="#how">See how it works</a>
    </div>
  </div>
</section>

<section class="about-band">
  <div class="wrap">
    <div class="sec-head"><div><h2>Who can post</h2><p class="sub">You do not need a big company to hire through Trabaho Tu Bunawan.</p></div></div>
    <div class="grid grid-3">
      <div class="card how who-card">
        <span class="how-ico"><?= icon('building', 24) ?></span>
        <h3>Registered businesses</h3>
        <p>Stores, restaurants, hardware, clinics, and other businesses with a Mayor's or Business Permit.</p>
        <span class="proof"><?= icon('file', 14) ?> Business Permit</span>
      </div>
      <div class="card how who-card">
        <span class="how-ico"><?= icon('users', 24) ?></span>
        <h3>Organizations</h3>
        <p>Cooperatives, schools, NGOs, and government offices with a registration or accreditation number.</p>
        <span class="proof"><?= icon('file', 14) ?> Registration number</span>
      </div>
      <div class="card how who-card">
        <span class="how-ico"><?= icon('home', 24) ?></span>
        <h3>Individuals and households</h3>
        <p>Farm owners, contractors, and families who need helpers. A Barangay Certification is enough.</p>
        <span class="proof"><?= icon('file', 14) ?> Barangay Certification</span>
      </div>
    </div>
  </div>
</section>

<section class="about-band" id="how">
  <div class="wrap">
    <div class="about-panel">
      <div class="sec-head"><div><h2>How it works</h2><p class="sub">Verification keeps job seekers safe and your listings trusted.</p></div></div>
      <div class="steps-flow">
        <div class="step-card"><span class="num">1</span><h3>Register</h3><p>Create an employer account and tell us who you are and where you operate in Bunawan.</p></div>
        <div class="step-card"><span class="num">2</span><h3>Get verified</h3><p>Our admin reviews your details, documents, and references. This is what earns your verified badge.</p></div>
        <div class="step-card"><span class="num">3</span><h3>Post a vacancy</h3><p>Describe the job, set a deadline, and choose whether a resume is needed. Each post is checked before it goes live.</p></div>
        <div class="step-card"><span class="num">4</span><h3>Review applicants</h3><p>See who applied, update their status, and contact them directly.</p></div>
      </div>
    </div>
  </div>
</section>

<section class="about-band">
  <div class="wrap">
    <div class="sec-head"><div><h2>What we ask for</h2><p class="sub">Documents and details depend on the type of employer.</p></div></div>
    <div class="ask-grid">
      <div class="ask-card">
        <h3>For everyone</h3>
        <ul class="plan-list">
          <li>Your complete Bunawan address with barangay and landmark, a contact person and mobile number, and a Facebook page if you have one.</li>
          <li><b>Workplace photos:</b> the storefront, signage, or farm or work area.</li>
          <li><b>Two local references:</b> name, number, and relationship (for example a barangay official, supplier, or employee).</li>
          <li><b>Agreements:</b> no fees charged to applicants, honest job posts, and consent under the Data Privacy Act (RA 10173).</li>
        </ul>
      </div>
      <div class="ask-card">
        <h3>Depending on who you are</h3>
        <div class="ask-types">
          <div class="ask-type"><span class="how-ico"><?= icon('building', 22) ?></span><div><b>Registered businesses</b><p>Business Permit number and expiry date, plus DTI or SEC registration if available.</p></div></div>
          <div class="ask-type"><span class="how-ico"><?= icon('home', 22) ?></span><div><b>Individuals and households</b><p>A Barangay Certification.</p></div></div>
          <div class="ask-type"><span class="how-ico"><?= icon('users', 22) ?></span><div><b>Organizations</b><p>Your registration or accreditation number.</p></div></div>
        </div>
      </div>
    </div>
    <p class="privacy-note"><?= icon('lock', 18) ?> Your documents are stored privately and are only seen by our admin team.</p>
  </div>
</section>

<section class="about-band">
  <div class="wrap">
    <div class="sec-head"><div><h2>Free and Premium</h2><p class="sub">Start free. Upgrade when you want more visibility.</p></div></div>
    <div class="grid plans emp-plans">
      <div class="card plan-card">
        <div class="plan-head"><h3>Free</h3></div>
        <p class="price">₱0</p>
        <ul class="plan-list"><li>Verified employer badge</li><li>Post jobs (limited active posts)</li><li>Applicant management</li><li>Basic statistics</li></ul>
      </div>
      <div class="card plan-card plan-premium">
        <div class="plan-head"><h3>Premium</h3><span class="badge badge-gold">&#9733; Featured</span></div>
        <p class="price">₱49 <small>per month</small></p>
        <ul class="plan-list"><li>Everything in Free</li><li>Featured on the home page</li><li>Gold Premium badge</li><li>Higher ranking in search</li><li>Pick which posts to boost, ₱49 per month for each post</li></ul>
        <p class="small">Pay online (GCash, Maya, or bank transfer): choose a method, send your request, and the admin activates it after receiving your payment.</p>
      </div>
    </div>
  </div>
</section>

<section class="about-band">
  <div class="wrap">
    <div class="banner">
      <div><h2>Ready to find local talent?</h2><p>Register your business, organization, or household and post your first vacancy for free.</p></div>
      <a class="btn btn-gold" href="<?= $postUrl ?>">Register as an employer</a>
    </div>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>