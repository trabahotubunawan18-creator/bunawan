<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/upload.php';

$id = (int)($_GET['id'] ?? 0);
$pdo = db();

// Only approved, non-expired jobs from verified employers are public.
$st = $pdo->prepare("SELECT j.*, ep.employer_name, ep.employer_type, ep.description AS employer_desc, ep.verification_status,
        ep.logo_path, ep.facebook_url, ep.peso_endorsed, ep.contact_phone, b.name AS barangay, c.name AS category,
        CASE WHEN EXISTS(SELECT 1 FROM premium_subscriptions ps WHERE ps.employer_id = j.employer_id AND ps.status='active' AND ps.ends_on >= CURRENT_DATE) THEN 1 ELSE 0 END AS is_premium
    FROM job_posts j
    JOIN employer_profiles ep ON ep.user_id = j.employer_id
    JOIN barangays b ON b.id = j.barangay_id
    JOIN job_categories c ON c.id = j.category_id
    WHERE j.id = ? AND j.status = 'approved' AND j.deadline >= CURRENT_DATE
      AND ep.verification_status IN ('verified_business','verified_local')");
$st->execute([$id]);
$job = $st->fetch();

if (!$job) {
    http_response_code(404);
    $pageTitle = 'Job not available';
    require __DIR__ . '/includes/header.php'; ?>
    <section class="wrap section"><div class="card empty">
      <h2>This job is no longer available</h2>
      <p>It may have been filled, closed, or expired. Browse the latest vacancies instead.</p>
      <a class="btn btn-primary" href="<?= url('jobs.php') ?>">Find Jobs</a>
    </div></section>
    <?php require __DIR__ . '/includes/footer.php'; exit;
}

$uid  = $_SESSION['uid'] ?? 0;
$role = $_SESSION['role'] ?? '';
$errors = [];
$reportErrors = [];
$self = 'job.php?id=' . $id;

// Current user's state for this job
$applied = null; $saved = false; $me = null;
if ($role === 'seeker') {
    $a = $pdo->prepare('SELECT status FROM job_applications WHERE job_id = ? AND seeker_id = ?');
    $a->execute([$id, $uid]); $applied = $a->fetchColumn() ?: null;
    $sv = $pdo->prepare('SELECT 1 FROM saved_jobs WHERE job_id = ? AND seeker_id = ?');
    $sv->execute([$id, $uid]); $saved = (bool)$sv->fetchColumn();
    $m = $pdo->prepare('SELECT full_name, phone FROM users WHERE id = ?');
    $m->execute([$uid]); $me = $m->fetch();
}
$form = ['name' => $me['full_name'] ?? '', 'phone' => $me['phone'] ?? '', 'message' => ''];

// Skills: what the job needs, what this seeker has (from the profile), and how well they match
$jobSkills = job_skill_map($pdo, [$id])[$id] ?? [];
$mySkills  = $role === 'seeker' ? seeker_skill_list($pdo, (int)$uid) : [];
$selSkills = $mySkills;   // pre-filled in the apply form; the seeker can change it for this application
$myMatch   = ($jobSkills && $mySkills) ? skill_match(array_column($mySkills, 'key'), $jobSkills) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($act === 'save' || $act === 'unsave') {
        if ($role !== 'seeker') { flash('info', 'Log in as a job seeker to save jobs.'); redirect('login.php'); }
        if ($act === 'save') $pdo->prepare('INSERT INTO saved_jobs (seeker_id, job_id) VALUES (?, ?) ON CONFLICT DO NOTHING')->execute([$uid, $id]);
        else $pdo->prepare('DELETE FROM saved_jobs WHERE seeker_id = ? AND job_id = ?')->execute([$uid, $id]);
        flash('success', $act === 'save' ? 'Job saved to your dashboard.' : 'Job removed from your saved list.');
        redirect($self);
    } elseif ($act === 'apply') {
        if (!is_logged_in()) { flash('info', 'Please log in or create an account to apply.'); redirect('login.php'); }
        if ($role !== 'seeker') {
            $errors[] = 'Only job seeker accounts can apply for jobs.';
        } else {
            $form['name'] = trim($_POST['name'] ?? '');
            $form['phone'] = preg_replace('/[\s-]/', '', $_POST['phone'] ?? '');
            $form['message'] = trim($_POST['message'] ?? '');
            if (mb_strlen($form['name']) < 2 || mb_strlen($form['name']) > 100) $errors[] = 'Enter your full name.';
            if (!preg_match('/^(09|\+639)\d{9}$/', $form['phone'])) $errors[] = 'Enter a valid mobile number, like 09123456789.';
            if (mb_strlen($form['message']) > 1000) $errors[] = 'Your message is too long (maximum 1000 characters).';
            if ($applied) $errors[] = 'You have already applied for this job.';
            $selSkills = skills_from_post($pdo, $errors, SKILL_MAX_SEEKER);

            $resumePath = null;
            if ($job['resume_requirement'] !== 'none') {
                [$resumePath, $upErr] = save_private_upload($_FILES['resume'] ?? [], RESUME_TYPES, 2 * 1048576, 'resumes');
                if ($upErr) $errors[] = $upErr;
                if ($job['resume_requirement'] === 'required' && !$resumePath && !$upErr) $errors[] = 'This employer requires a resume (PDF or DOCX, up to 2 MB).';
            }
            if (!$errors) {
                try {
                    $pdo->prepare('INSERT INTO job_applications (job_id, seeker_id, applicant_name, applicant_phone, message, resume_path) VALUES (?,?,?,?,?,?)')
                        ->execute([$id, $uid, $form['name'], $form['phone'], $form['message'] ?: null, $resumePath]);
                    $appId = (int)$pdo->lastInsertId();
                    if (skills_ready($pdo)) skills_save($pdo, 'application_skills', $appId, $selSkills);   // snapshot for the employer
                    $pdo->prepare('INSERT INTO notifications (user_id, message, link) VALUES (?,?,?)')
                        ->execute([$job['employer_id'], 'New applicant for ' . $job['title'], 'employer/applicants.php?job=' . $id]);
                    flash('success', 'Application sent. The employer will review it, and you can track its status in your dashboard.');
                    redirect($self);
                } catch (PDOException $e) {
                    $errors[] = 'You have already applied for this job.';
                }
            }
        }
    } elseif ($act === 'report') {
        $reasons = ['scam', 'asks_payment', 'wrong_info', 'discrimination', 'other'];
        $reason = $_POST['reason'] ?? '';
        $details = mb_substr(trim($_POST['details'] ?? ''), 0, 500);
        if (!in_array($reason, $reasons, true)) $reportErrors[] = 'Choose a reason for your report.';
        if (rate_count('report', 60) >= 5) $reportErrors[] = 'You have sent several reports recently. Please try again later.';
        if (!$reportErrors) {
            rate_hit('report');
            $pdo->prepare('INSERT INTO job_reports (job_id, reporter_id, reason, details) VALUES (?,?,?,?)')
                ->execute([$id, $uid ?: null, $reason, $details ?: null]);
            require_once __DIR__ . '/includes/redflags.php';
            $rc = $pdo->prepare("SELECT COUNT(DISTINCT reporter_id) FROM job_reports WHERE job_id = ? AND status = 'open' AND reporter_id IS NOT NULL");
            $rc->execute([$id]);
            if ((int)$rc->fetchColumn() >= REPORT_HIDE_AT) {
                $pdo->prepare("UPDATE job_posts SET status = 'pending', red_flags = CONCAT_WS(',', NULLIF(red_flags, ''), 'reported') WHERE id = ? AND status = 'approved'")->execute([$id]);
                notify_admins($pdo, 'A job was hidden after several reports: ' . $job['title'], 'admin/job-review.php?id=' . $id . '&from=flagged');
            }
            flash('success', 'Thank you. Our admin team will review this listing.');
            redirect($self);
        }
    }
}

// Count one view per visitor session
if ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($_SESSION['viewed'][$id])) {
    $pdo->prepare('UPDATE job_posts SET views = views + 1 WHERE id = ?')->execute([$id]);
    $_SESSION['viewed'][$id] = 1;
}

// Similar jobs
$sim = $pdo->prepare(PUBLIC_JOB_SQL . ' AND j.category_id = :c AND j.id <> :id ORDER BY j.created_at DESC LIMIT 3');
$sim->execute(['c' => $job['category_id'], 'id' => $id]);
$similar = $sim->fetchAll();
attach_skill_info($pdo, $similar, $mySkills ? skill_list_map($mySkills) : null);

$fullUrl = (!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . url($self);
$hasPin = $job['latitude'] !== null && $job['longitude'] !== null;
$mapUrl = $hasPin
    ? 'https://www.google.com/maps/search/?api=1&query=' . $job['latitude'] . ',' . $job['longitude']
    : 'https://www.google.com/maps/search/?api=1&query=' . urlencode(($job['work_address'] ?: $job['barangay']) . ', Bunawan, Agusan del Sur');
$resumeText = ['none' => 'No resume needed', 'optional' => 'Resume is optional', 'required' => 'Resume is required'][$job['resume_requirement']];

// Presentation helpers
$left    = days_left($job['deadline']);
$leftTxt = $left <= 0 ? 'Last day to apply' : $left . ' day' . ($left > 1 ? 's' : '') . ' left';
$urgent  = $left <= 3;
function jd_lines(?string $t): array {
    $out = [];
    foreach (preg_split('/\R/', (string)$t) as $ln) {
        $ln = trim(preg_replace('/^[\s\-\*\x{2022}\x{25CF}]+/u', '', $ln));
        if ($ln !== '') $out[] = $ln;
    }
    return $out;
}
function jd_text(?string $t): void {
    $l = jd_lines($t);
    if (!$l) return;
    if (count($l) === 1) { echo '<p>' . e($l[0]) . '</p>'; return; }
    echo '<ul class="jd-list">';
    foreach ($l as $x) echo '<li>' . e($x) . '</li>';
    echo '</ul>';
}
$canApplyNow = ($role === 'seeker' && !$applied) || !is_logged_in();

$pageTitle = $job['title'];
$inDash = ($role === 'seeker');
require __DIR__ . '/includes/' . ($inDash ? 'dash_top.php' : 'header.php');
?>
<?php if (!$inDash): ?>
<section class="page-hero">
  <div class="wrap">
    <p class="crumbs"><a href="<?= url('jobs.php') ?>">Find Jobs</a> / <?= e($job['category']) ?></p>
    <h1><?= e($job['title']) ?></h1>
    <p><?= e($job['employer_name']) ?> &middot; <?= e($job['barangay']) ?>, Bunawan</p>
  </div>
</section>
<?php endif; ?>

<section class="wrap section jd">
  <?php if ($inDash): ?>
  <a class="back-link jd-back" href="<?= url('seeker/browse.php') ?>">&larr; Back to jobs</a>
  <header class="jd-head">
    <div class="avatar jd-av" aria-hidden="true"><?php if ($job['logo_path']): ?><img src="<?= public_file_url($job['logo_path']) ?>" alt=""><?php else: ?><?= e(initials($job['employer_name'])) ?><?php endif; ?></div>
    <div class="jd-title">
      <h1 class="dash-title"><?= e($job['title']) ?></h1>
      <p><span><?= e($job['employer_name']) ?></span><span><?= icon('pin', 14) ?> <?= e($job['barangay']) ?>, Bunawan</span><span><?= icon('briefcase', 14) ?> <?= e($job['category']) ?></span></p>
    </div>
    <span class="jd-left<?= $urgent ? ' is-urgent' : '' ?>"><?= icon('calendar', 14) ?> <?= e($leftTxt) ?></span>
  </header>
  <?php endif; ?>

  <?php if ($errors): ?><div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

  <div class="detail">
    <div class="detail-main">
      <div class="card jd-card">
        <div class="jd-chips">
          <?php if ($job['is_premium']): ?><span class="badge badge-gold">&#9733; Featured</span><?php endif; ?>
          <span class="badge badge-ver"><?= icon('shield', 14) ?> <?= e(verified_label($job['verification_status'])) ?></span>
          <?php if ($job['peso_endorsed']): ?><span class="badge badge-ver">PESO Bunawan endorsed</span><?php endif; ?>
          <?php if ($job['student_friendly']): ?><span class="tag">Student-friendly</span><?php endif; ?>
          <?php if (($job['work_schedule'] ?? 'any') !== 'any'): ?><span class="tag"><?= e(WORK_SCHEDULES[$job['work_schedule']] ?? '') ?></span><?php endif; ?>
        </div>
        <div class="jd-grid">
          <div class="jd-fact is-salary"><span class="jd-fi"><?= icon('tag', 18) ?></span><div><small>Salary</small><b><?= e(salary_text($job)) ?></b></div></div>
          <div class="jd-fact"><span class="jd-fi"><?= icon('briefcase', 18) ?></span><div><small>Employment type</small><b><?= e(type_label($job['employment_type'])) ?></b></div></div>
          <div class="jd-fact"><span class="jd-fi"><?= icon('users', 18) ?></span><div><small>Open slots</small><b><?= (int)$job['slots'] ?></b></div></div>
          <div class="jd-fact"><span class="jd-fi"><?= icon('clock', 18) ?></span><div><small>Posted</small><b><?= e(date('M j, Y', strtotime($job['created_at']))) ?></b></div></div>
          <div class="jd-fact"><span class="jd-fi"><?= icon('calendar', 18) ?></span><div><small>Application deadline</small><b><?= e(date('M j, Y', strtotime($job['deadline']))) ?></b><em class="jd-flag<?= $urgent ? ' is-urgent' : '' ?>"><?= e($leftTxt) ?></em></div></div>
          <div class="jd-fact"><span class="jd-fi"><?= icon('file', 18) ?></span><div><small>Resume</small><b><?= e($resumeText) ?></b></div></div>
        </div>
      </div>

      <div class="card jd-card">
        <h2 class="jd-h"><?= icon('list', 18) ?> Job description</h2>
        <p class="jd-p"><?= nl2br(e($job['description'])) ?></p>
        <?php if (jd_lines($job['responsibilities'])): ?><h3 class="jd-h3">Responsibilities</h3><?php jd_text($job['responsibilities']); ?><?php endif; ?>
        <?php if (jd_lines($job['qualifications'])): ?><h3 class="jd-h3">Qualifications and requirements</h3><?php jd_text($job['qualifications']); ?><?php endif; ?>

        <?php if ($jobSkills): ?>
          <h3 class="jd-h3">Skills needed</h3>
          <?php if ($myMatch): ?>
            <div class="skm-head"><span class="small">Your skills compared with this job</span>
              <span class="skm-pct <?= e(skill_badge_class($myMatch['pct'])) ?>"><?= (int)$myMatch['pct'] ?>% match</span></div>
            <div class="skm"><?php foreach ($jobSkills as $sk => $lbl): $hit = in_array($lbl, $myMatch['matched'], true); ?>
              <span class="skm-pill <?= $hit ? 'is-hit' : 'is-miss' ?>"><?= $hit ? icon('check', 13) : '' ?> <?= e($lbl) ?></span><?php endforeach; ?></div>
            <p class="sk-note"><?= count($myMatch['matched']) ?> of <?= (int)$myMatch['need'] ?> skills match. Missing a skill? You can still apply.</p>
          <?php else: ?>
            <div class="skm"><?php foreach ($jobSkills as $lbl): ?><span class="skm-pill"><?= e($lbl) ?></span><?php endforeach; ?></div>
            <?php if ($role === 'seeker'): ?><p class="sk-note"><a href="<?= url('seeker/profile.php') ?>">Add your skills to your profile</a> to see how well you match this job.</p><?php endif; ?>
          <?php endif; ?>
        <?php endif; ?>

        <h3 class="jd-h3">How to apply</h3>
        <ol class="jd-steps">
          <li><b>1</b><span>Fill in the Apply form</span></li>
          <li><b>2</b><span>The employer reviews it</span></li>
          <li><b>3</b><span>You get a call if shortlisted</span></li>
        </ol>
        <p class="jd-p small">Only your name, mobile number, and a short message are needed<?= $job['resume_requirement'] === 'none' ? '.' : ', plus a resume (PDF or DOCX, up to 2 MB) as noted above.' ?></p>
      </div>

      <div class="card jd-card">
        <h2 class="jd-h"><?= icon('pin', 18) ?> Work location</h2>
        <p class="jd-addr"><?= e($job['work_address'] ?: $job['barangay']) ?>, Barangay <?= e($job['barangay']) ?>, Bunawan, Agusan del Sur</p>
        <?php if ($hasPin): ?>
          <div id="job-map" class="jd-map"></div>
          <p class="jd-maplinks"><a href="<?= e($mapUrl) ?>" target="_blank" rel="noopener noreferrer">Open in Google Maps</a> &middot; <a href="https://www.google.com/maps/dir/?api=1&destination=<?= e($job['latitude'] . ',' . $job['longitude']) ?>" target="_blank" rel="noopener noreferrer">Get directions</a></p>
          <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
          <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
          <script>
          (function(){var ll=[<?= (float)$job['latitude'] ?>,<?= (float)$job['longitude'] ?>];var m=L.map('job-map').setView(ll,17);
            var st=L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap'}).addTo(m);
            var sa=L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',{maxZoom:19,attribution:'Imagery &copy; Esri'});
            L.control.layers({'Street':st,'Satellite':sa}).addTo(m);L.marker(ll).addTo(m).bindPopup(<?= json_encode($job['employer_name']) ?>);})();
          </script>
        <?php else: ?>
          <iframe class="jd-frame" title="Map of the work location" loading="lazy" referrerpolicy="no-referrer-when-downgrade"
            src="https://www.google.com/maps?q=<?= urlencode(($job['work_address'] ?: $job['barangay']) . ', Bunawan, Agusan del Sur') ?>&output=embed"></iframe>
          <p class="jd-maplinks"><a href="<?= e($mapUrl) ?>" target="_blank" rel="noopener noreferrer">Open in Google Maps</a></p>
        <?php endif; ?>
      </div>

      <div class="card jd-card">
        <h2 class="jd-h"><?= icon('building', 18) ?> About the employer</h2>
        <div class="jd-emp">
          <div class="avatar jd-emp-av" aria-hidden="true"><?php if ($job['logo_path']): ?><img src="<?= public_file_url($job['logo_path']) ?>" alt=""><?php else: ?><?= e(initials($job['employer_name'])) ?><?php endif; ?></div>
          <div>
            <b><?= e($job['employer_name']) ?></b>
            <p class="jd-emp-ver"><span class="badge badge-ver"><?= icon('shield', 14) ?> <?= e(verified_label($job['verification_status'])) ?></span></p>
            <?php if ($job['employer_desc']): ?><p class="jd-p small"><?= e(mb_strimwidth($job['employer_desc'], 0, 300, '...')) ?></p><?php endif; ?>
            <?php if ($job['facebook_url'] && preg_match('#^https?://#i', $job['facebook_url'])): ?>
              <a class="btn btn-outline btn-sm" href="<?= e($job['facebook_url']) ?>" target="_blank" rel="noopener noreferrer">Employer Facebook page</a><?php endif; ?>
          </div>
        </div>
      </div>

      <div class="notice"><?= icon('shield', 18) ?> <span><b>Never pay to get hired.</b> Legitimate employers do not ask for placement, training, or application fees. If this listing asks for money, please report it.</span></div>
    </div>

    <aside class="detail-side jd-side">
      <div class="card jd-apply" id="apply">
        <div class="jd-apply-head"><h2>Apply for this job</h2><span class="jd-left<?= $urgent ? ' is-urgent' : '' ?>"><?= e($leftTxt) ?></span></div>
        <?php if ($role === 'seeker'): ?>
          <?php if ($applied): [$aLbl, $aCls] = app_status($applied); ?>
            <div class="jd-done"><span class="jd-done-ico"><?= icon('check', 18) ?></span><div><b>You applied for this job</b><span>Status: <span class="st <?= e($aCls) ?>"><?= e($aLbl) ?></span></span></div></div>
            <p class="small" style="margin:12px 0 0">Track it in <a href="<?= url('seeker/applications.php') ?>">My Applications</a>.</p>
          <?php else: ?>
            <form method="post" enctype="multipart/form-data" class="form" novalidate>
              <?= csrf_field() ?><input type="hidden" name="action" value="apply">
              <label for="a-name">Full name</label>
              <input id="a-name" name="name" maxlength="100" value="<?= e($form['name']) ?>" required>
              <label for="a-phone">Mobile number</label>
              <input id="a-phone" name="phone" type="tel" placeholder="09123456789" value="<?= e($form['phone']) ?>" required>
              <label for="a-msg">Message to the employer (optional)</label>
              <textarea id="a-msg" name="message" rows="3" maxlength="1000" placeholder="Tell the employer why you are a good fit."><?= e($form['message']) ?></textarea>
              <label id="a-skills-lbl">Your skills for this job</label>
              <p class="sk-note" style="margin:0 0 6px">Taken from your profile. Untick any that do not apply, or add others.</p>
              <div class="skp-apply"><?php render_skill_picker($pdo, 'a-skills', $selSkills, SKILL_MAX_CUSTOM, SKILL_MAX_SEEKER, array_keys($jobSkills)); ?></div>
              <?php if ($job['resume_requirement'] !== 'none'): ?>
                <label for="a-resume">Resume (<?= $job['resume_requirement'] === 'required' ? 'required' : 'optional' ?>, PDF or DOCX, up to 2 MB)</label>
                <input id="a-resume" name="resume" type="file" accept=".pdf,.docx" <?= $job['resume_requirement'] === 'required' ? 'required' : '' ?>>
              <?php endif; ?>
              <button class="btn btn-primary block" type="submit"><?= icon('send', 16) ?> Apply Now</button>
              <p class="jd-fine">Free to apply. Employers never charge fees.</p>
            </form>
          <?php endif; ?>
        <?php elseif ($role === 'employer' || $role === 'admin'): ?>
          <p class="small">Only job seeker accounts can apply. Log in with a job seeker account to apply.</p>
        <?php else: ?>
          <p class="small">Log in or create a free account to apply. It only takes a minute.</p>
          <a class="btn btn-primary block" href="<?= url('login.php') ?>">Log in to apply</a>
          <a class="btn btn-outline block" style="margin-top:8px" href="<?= url('login.php?tab=register') ?>">Create account</a>
        <?php endif; ?>
      </div>

      <div class="card jd-actions">
        <?php if ($role === 'seeker'): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $saved ? 'unsave' : 'save' ?>">
            <button class="btn btn-outline block jd-save<?= $saved ? ' is-saved' : '' ?>" type="submit"><?= icon('bookmark', 16) ?> <?= $saved ? 'Saved (click to remove)' : 'Save Job' ?></button></form>
        <?php elseif (!is_logged_in()): ?>
          <a class="btn btn-outline block jd-save" href="<?= url('login.php') ?>"><?= icon('bookmark', 16) ?> Log in to save this job</a>
        <?php endif; ?>
        <div class="jd-act2">
          <a class="btn btn-outline btn-sm" href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($fullUrl) ?>" target="_blank" rel="noopener noreferrer">Share on Facebook</a>
          <a class="btn btn-outline btn-sm" href="<?= e($mapUrl) ?>" target="_blank" rel="noopener noreferrer"><?= icon('map', 15) ?> View on map</a>
        </div>
        <details class="report">
          <summary><?= icon('flag', 14) ?> Report this listing</summary>
          <?php if ($reportErrors): ?><div class="alert alert-error"><?= e(implode(' ', $reportErrors)) ?></div><?php endif; ?>
          <form method="post" class="form">
            <?= csrf_field() ?><input type="hidden" name="action" value="report">
            <label for="r-reason">Reason</label>
            <select id="r-reason" name="reason" required>
              <option value="">Choose a reason</option>
              <option value="scam">Looks like a scam</option>
              <option value="asks_payment">Asks for payment or fees</option>
              <option value="wrong_info">Wrong or misleading information</option>
              <option value="discrimination">Discrimination</option>
              <option value="other">Other</option>
            </select>
            <label for="r-details">Details (optional)</label>
            <textarea id="r-details" name="details" rows="3" maxlength="500"></textarea>
            <button class="btn btn-gold block" type="submit">Send report</button>
          </form>
        </details>
      </div>
    </aside>
  </div>

  <?php if ($similar): ?>
    <div class="sec-head jd-similar"><div><h2>Similar jobs</h2><p class="sub">More openings in <?= e($job['category']) ?>.</p></div></div>
    <div class="grid"><?php foreach ($similar as $j) render_job_card($j); ?></div>
  <?php endif; ?>

  <?php if ($canApplyNow): ?>
  <div class="jd-mbar"><div><b><?= e(salary_text($job)) ?></b><small><?= e($leftTxt) ?></small></div><a class="btn btn-primary" href="#apply"><?= icon('send', 16) ?> Apply now</a></div>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/' . ($inDash ? 'dash_bottom.php' : 'footer.php'); ?>
