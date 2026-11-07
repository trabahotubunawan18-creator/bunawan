<?php
$pageTitle = 'Home';
require __DIR__ . '/includes/header.php';
$pdo = db();

// Live statistics from the database
$activeJobs = (int)$pdo->query("SELECT COUNT(*) FROM job_posts j JOIN employer_profiles ep ON ep.user_id = j.employer_id
    WHERE j.status='approved' AND j.deadline >= CURRENT_DATE AND ep.verification_status IN ('verified_business','verified_local')")->fetchColumn();
$employers  = (int)$pdo->query("SELECT COUNT(*) FROM employer_profiles ep JOIN users u ON u.id = ep.user_id
    WHERE ep.verification_status IN ('verified_business','verified_local') AND u.status='active'")->fetchColumn();
$seekers    = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='seeker' AND status='active'")->fetchColumn();

$barangays  = $pdo->query('SELECT id, name FROM barangays WHERE is_active = 1 ORDER BY name')->fetchAll();
$categories = $pdo->query("SELECT c.id, c.name, COUNT(j.id) AS cnt
    FROM job_categories c
    LEFT JOIN job_posts j ON j.category_id = c.id AND j.status='approved' AND j.deadline >= CURRENT_DATE
    WHERE c.is_active = 1 GROUP BY c.id, c.name ORDER BY c.sort_order")->fetchAll();

// Job posts with an active Premium (per post) appear in the featured section
$featured = $pdo->query(PUBLIC_JOB_SQL . ' AND ' . PREMIUM_JOB_COND . ' ORDER BY j.created_at DESC LIMIT 4')->fetchAll();
$latest   = $pdo->query(PUBLIC_JOB_SQL . ' ORDER BY j.created_at DESC LIMIT 6')->fetchAll();
$skillOpts = skills_in_use($pdo);
$homeKeys = (($_SESSION['role'] ?? '') === 'seeker') ? skill_list_map(seeker_skill_list($pdo, (int)$_SESSION['uid'])) : [];
attach_skill_info($pdo, $featured, $homeKeys ?: null);
attach_skill_info($pdo, $latest, $homeKeys ?: null);
?>

<section class="hero">
  <div class="wrap hero-inner">
    <p class="pill"><?= icon('pin', 14) ?> Bunawan, Agusan del Sur</p>
    <h1>Your Next Opportunity Starts in Bunawan.</h1>
    <p class="tagline">Local Skills. Local Jobs. Better Futures.</p>
    <p class="lead">Every local vacancy in one place, from stores and farms to offices and households. No more searching through Facebook posts and group chats.</p>

    <form class="searchbar" action="<?= url('jobs.php') ?>" method="get" role="search">
      <div class="sfield wide"><label for="q">Job title or keyword</label>
        <input id="q" name="q" type="search" maxlength="100" placeholder="Example: cashier, farm helper"></div>
      <div class="sfield"><label for="barangay">Barangay</label>
        <select id="barangay" name="barangay"><option value="">All barangays</option>
          <?php foreach ($barangays as $b): ?><option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="sfield"><label for="skill">Skills</label>
        <select id="skill" name="skill"><option value="">All Skills</option>
          <?php foreach ($skillOpts as $so): ?><option value="<?= e($so['k']) ?>"><?= e($so['label']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="sfield"><label for="category">Category</label>
        <select id="category" name="category"><option value="">All categories</option>
          <?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
        </select></div>
      <button class="btn btn-leaf" type="submit">Find Jobs</button>
    </form>

    <div class="chips" aria-label="Quick filters">
      <a class="chip" href="<?= url('jobs.php?type=part_time') ?>">Part-time</a>
      <a class="chip" href="<?= url('jobs.php?type=full_time') ?>">Full-time</a>
      <a class="chip" href="<?= url('jobs.php?student=1') ?>">Student-friendly</a>
      <a class="chip" href="<?= url('jobs.php?schedule=weekdays') ?>">Weekdays only</a>
      <a class="chip" href="<?= url('jobs.php?schedule=weekends') ?>">Weekend only</a>
      <a class="chip" href="<?= url('jobs.php?type=temporary') ?>">Temporary</a>
      <a class="chip" href="<?= url('jobs.php?type=internship') ?>">Internship</a>
    </div>
    <div class="cta-row">
      <a class="btn btn-white" href="<?= url('jobs.php') ?>">Find Jobs</a>
      <a class="btn btn-outline-gold" href="<?= url('register.php?role=employer') ?>">Post a Job</a>
    </div>
    <ul class="trust">
      <li><?= icon('check') ?> Free for job seekers</li>
      <li><?= icon('shield') ?> Employers checked by our team</li>
      <li><?= icon('map') ?> Bunawan jobs only</li>
    </ul>
  </div>
</section>

<section class="wrap stats-wrap">
  <div class="card stats-panel">
    <div class="stat"><strong><?= number_format($activeJobs) ?></strong><span>Active Jobs</span></div>
    <div class="stat"><strong><?= number_format($employers) ?></strong><span>Verified Employers</span></div>
    <div class="stat"><strong><?= number_format($seekers) ?></strong><span>Job Seekers</span></div>
  </div>
</section>

<?php if ($featured): ?>
<section class="wrap section">
  <div class="sec-head"><div><h2>Featured jobs</h2><p class="sub">Premium listings from local employers.</p></div>
    <a class="more" href="<?= url('jobs.php') ?>">View all jobs <?= icon('arrow') ?></a></div>
  <div class="grid"><?php foreach ($featured as $j) render_job_card($j, true); ?></div>
</section>
<?php endif; ?>

<section class="wrap section">
  <div class="sec-head"><div><h2>Browse by category</h2><p class="sub">Find work that fits your skills and schedule.</p></div></div>
  <div class="grid grid-cat">
    <?php foreach ($categories as $c): ?>
      <a class="card cat" href="<?= url('jobs.php?category=' . (int)$c['id']) ?>">
        <span class="ic"><img src="<?= url('assets/img/' . cat_icon($c['name'])) ?>" alt="" width="28" height="28" onerror="this.style.display='none'"></span>
        <span><b><?= e($c['name']) ?></b><small><?= (int)$c['cnt'] ?> <?= (int)$c['cnt'] === 1 ? 'job' : 'jobs' ?></small></span>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<section class="wrap section">
  <div class="sec-head"><div><h2>Latest job opportunities</h2><p class="sub">Freshly posted and approved by our team.</p></div>
    <a class="more" href="<?= url('jobs.php') ?>">View all jobs <?= icon('arrow') ?></a></div>
  <?php if ($latest): ?>
    <div class="grid"><?php foreach ($latest as $j) render_job_card($j); ?></div>
  <?php else: ?>
    <div class="card empty">
      <img src="<?= url('assets/img/empty-no-jobs.svg') ?>" alt="" width="160" onerror="this.style.display='none'">
      <h3>No jobs posted yet</h3>
      <p>Be the first local employer to post a vacancy in Bunawan.</p>
      <a class="btn btn-primary" href="<?= url('register.php?role=employer') ?>">Post a Job</a>
    </div>
  <?php endif; ?>
</section>

<section class="wrap section">
  <div class="sec-head"><div><h2>How it works</h2><p class="sub">Simple for job seekers, safe for employers.</p></div></div>
  <div class="grid grid-3">
    <div class="card how">
      <img src="<?= url('assets/img/step-search.svg') ?>" alt="" width="72" height="72" onerror="this.style.display='none'">
      <h3>For job seekers</h3>
      <p>Search by barangay, category, or keyword. Send a short application, and attach a resume only if the employer asks. Then track your status from your dashboard.</p>
      <a href="<?= url('register.php') ?>">Create a free account</a>
    </div>
    <div class="card how">
      <img src="<?= url('assets/img/step-verify.svg') ?>" alt="" width="72" height="72" onerror="this.style.display='none'">
      <h3>For employers</h3>
      <p>Register as a business, organization, or individual. Our admin team verifies your account, then you can post vacancies and review applicants.</p>
      <a href="<?= url('register.php?role=employer') ?>">Register as an employer</a>
    </div>
    <div class="card how how-safe">
      <img src="<?= url('assets/img/badge-verified-business.svg') ?>" alt="" width="72" height="72" onerror="this.style.display='none'">
      <h3>Never pay to get hired</h3>
      <p>Real local employers do not charge applicants. See something suspicious? Use the Report button on any listing.</p>
      <a href="<?= url('safety.php') ?>">Read safety tips</a>
    </div>
  </div>
</section>

<section class="wrap section">
  <div class="banner">
    <div>
      <h2>Need a worker? Post a vacancy for free.</h2>
      <p>Stores, farms, contractors, offices, and households are all welcome in Bunawan. Want more visibility? Premium is <b>₱49 per month for each job post</b> you choose, activated by our admin.</p>
    </div>
    <a class="btn btn-gold" href="<?= url('register.php?role=employer') ?>">Post a Job</a>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>