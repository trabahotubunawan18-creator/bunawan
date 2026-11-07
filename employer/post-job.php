<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/redflags.php';
require_role('employer');
$pdo = db(); $uid = (int)$_SESSION['uid'];

$s = $pdo->prepare('SELECT ep.employer_name, ep.verification_status, ep.barangay_id, ep.approved_post_count FROM employer_profiles ep WHERE ep.user_id = ?');
$s->execute([$uid]);
$emp = $s->fetch();
[$vLabel, $vClass, $canPost] = verif_info($emp['verification_status'] ?? 'pending');

$categories = $pdo->query('SELECT id, name FROM job_categories WHERE is_active = 1 ORDER BY sort_order')->fetchAll();
$barangays  = $pdo->query('SELECT id, name FROM barangays WHERE is_active = 1 ORDER BY name')->fetchAll();
$types = ['full_time' => 'Full-time', 'part_time' => 'Part-time', 'temporary' => 'Temporary', 'internship' => 'Internship'];
$periods = ['negotiable' => 'Negotiable', 'day' => 'Per day', 'week' => 'Per week', 'month' => 'Per month', 'hour' => 'Per hour'];
$limits = employer_post_limits($pdo, $uid);

// Editing (?id=) or duplicating (?copy=)
$editId = (int)($_GET['id'] ?? 0); $copyId = (int)($_GET['copy'] ?? 0);
$job = null;
if ($editId || $copyId) {
    $q = $pdo->prepare('SELECT * FROM job_posts WHERE id = ? AND employer_id = ? AND status <> \'removed\'');
    $q->execute([$editId ?: $copyId, $uid]);
    $job = $q->fetch();
    if (!$job) { flash('error', 'That job was not found.'); redirect('employer/my-jobs.php'); }
}
$editing = $editId && $job;
$reopening = $editing && in_array($job['status'], ['closed', 'expired'], true);
$isNew = !$editing || $reopening;

// Map pin: use this job's pin, else the employer's most recent pin
$lastPin = $pdo->prepare('SELECT latitude, longitude FROM job_posts WHERE employer_id = ? AND latitude IS NOT NULL ORDER BY id DESC LIMIT 1');
$lastPin->execute([$uid]);
$lastPin = $lastPin->fetch() ?: ['latitude' => '', 'longitude' => ''];

$v = [
    'lat' => (string)($job['latitude'] ?? $lastPin['latitude']), 'lng' => (string)($job['longitude'] ?? $lastPin['longitude']),
    'title' => $job['title'] ?? '', 'category' => (string)($job['category_id'] ?? ''), 'custom_category' => $job['custom_category'] ?? '', 'description' => $job['description'] ?? '',
    'responsibilities' => $job['responsibilities'] ?? '', 'qualifications' => $job['qualifications'] ?? '',
    'type' => $job['employment_type'] ?? 'full_time', 'period' => $job['salary_period'] ?? 'negotiable',
    'smin' => $job['salary_min'] ?? '', 'smax' => $job['salary_max'] ?? '', 'barangay' => (string)($job['barangay_id'] ?? $emp['barangay_id'] ?? ''),
    'address' => $job['work_address'] ?? '', 'slots' => (string)($job['slots'] ?? 1),
    'deadline' => $copyId ? '' : ($job['deadline'] ?? date('Y-m-d', strtotime('+14 days'))),
    'resume' => $job['resume_requirement'] ?? 'none', 'student' => !empty($job['student_friendly']), 'schedule' => $job['work_schedule'] ?? 'any',
];
if ($v['smin'] !== '') $v['smin'] = rtrim(rtrim((string)$v['smin'], '0'), '.');
if ($v['smax'] !== '' && $v['smax'] !== null) $v['smax'] = rtrim(rtrim((string)$v['smax'], '0'), '.'); else $v['smax'] = '';
if ($copyId && $v['deadline'] === '') $v['deadline'] = date('Y-m-d', strtotime('+14 days'));
$selSkills = $job ? job_skill_list($pdo, (int)$job['id']) : [];   // skills required (kept when editing or copying)

// Reasons the employer cannot create a new post right now
$block = null;
if (!$canPost) $block = 'Posting is locked until your account is verified.';
elseif ($isNew && $limits['strikes'] >= FLAG_STRIKES) $block = 'Several of your recent posts were flagged. Posting is paused until our team reviews your account. Please contact us.';
elseif ($isNew && $limits['today'] >= DAILY_POST_LIMIT) $block = 'You reached today\'s limit of ' . DAILY_POST_LIMIT . ' new posts. Please try again tomorrow.';
elseif ($isNew && $limits['active'] >= $limits['limit']) $block = 'You have ' . $limits['active'] . ' of ' . $limits['limit'] . ' active posts. Close one to post another.';
elseif ($editing && !$reopening && !in_array($job['status'], ['approved', 'pending', 'rejected'], true)) $block = 'This job cannot be edited.';

$errors = [];
if (!$block && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) $errors[] = 'Your session expired. Please try again.';
    else {
        foreach (['title', 'category', 'custom_category', 'lat', 'lng', 'description', 'responsibilities', 'qualifications', 'type', 'period', 'smin', 'smax', 'barangay', 'address', 'slots', 'deadline', 'resume'] as $k)
            $v[$k] = trim((string)($_POST[$k] ?? ''));
        $v['student'] = !empty($_POST['student']);
        $v['schedule'] = (string)($_POST['schedule'] ?? 'any'); if (!isset(WORK_SCHEDULES[$v['schedule']])) $v['schedule'] = 'any';
        $v['smin'] = str_replace(',', '', $v['smin']); $v['smax'] = str_replace(',', '', $v['smax']);

        if (mb_strlen($v['title']) < 4 || mb_strlen($v['title']) > 150) $errors[] = 'Job title must be 4 to 150 characters.';
        $catOk = false; foreach ($categories as $c) if ((string)$c['id'] === $v['category']) $catOk = true;
        if (!$catOk) $errors[] = 'Choose a category.';
        $isOthers = false; foreach ($categories as $c) if ((string)$c['id'] === $v['category'] && strcasecmp($c['name'], 'Others') === 0) $isOthers = true;
        $v['custom_category'] = trim(preg_replace('/\s+/', ' ', $v['custom_category']));
        if ($isOthers) {
            if (mb_strlen($v['custom_category']) < 3 || mb_strlen($v['custom_category']) > 60) $errors[] = 'Tell us what kind of work this is (3 to 60 characters).';
        } else $v['custom_category'] = '';
        if (mb_strlen($v['description']) < 30) $errors[] = 'Describe the job in at least 30 characters.';
        if (mb_strlen($v['description']) > 3000) $errors[] = 'The description is too long (maximum 3000 characters).';
        if (mb_strlen($v['responsibilities']) > 2000 || mb_strlen($v['qualifications']) > 2000) $errors[] = 'Responsibilities and qualifications can each be up to 2000 characters.';
        if (!isset($types[$v['type']])) $errors[] = 'Choose an employment type.';
        if (!isset($periods[$v['period']])) $errors[] = 'Choose how the salary is paid.';
        $smin = null; $smax = null;
        if ($v['period'] !== 'negotiable') {
            if (!is_numeric($v['smin']) || (float)$v['smin'] <= 0 || (float)$v['smin'] > 500000) $errors[] = 'Enter a salary amount between ₱1 and ₱500,000, or choose Negotiable.';
            else {
                $smin = (float)$v['smin'];
                if ($v['smax'] !== '') {
                    if (!is_numeric($v['smax']) || (float)$v['smax'] < $smin || (float)$v['smax'] > 500000) $errors[] = 'The highest salary must be at least the lowest salary.';
                    else $smax = (float)$v['smax'];
                }
            }
        }
        $bOk = false; foreach ($barangays as $b) if ((string)$b['id'] === $v['barangay']) $bOk = true;
        if (!$bOk) $errors[] = 'Choose the barangay where the work is.';
        if (!is_numeric($v['lat']) || !is_numeric($v['lng'])) $errors[] = 'Drop the pin on the map to show exactly where the work is.';
        elseif ((float)$v['lat'] < 7.6 || (float)$v['lat'] > 8.7 || (float)$v['lng'] < 125.4 || (float)$v['lng'] > 126.5) $errors[] = 'The pin must be inside the Bunawan area. Please move it on the map.';
        else { $v['lat'] = number_format((float)$v['lat'], 7, '.', ''); $v['lng'] = number_format((float)$v['lng'], 7, '.', ''); }
        if (mb_strlen($v['address']) > 255) $errors[] = 'The work address is too long.';
        if (!ctype_digit($v['slots']) || (int)$v['slots'] < 1 || (int)$v['slots'] > 100) $errors[] = 'Open slots must be a number from 1 to 100.';
        $d = DateTime::createFromFormat('Y-m-d', $v['deadline']);
        if (!$d || $d->format('Y-m-d') !== $v['deadline']) $errors[] = 'Choose an application deadline.';
        elseif ($d < new DateTime('today') || $d > new DateTime('+' . MAX_DEADLINE_DAYS . ' days')) $errors[] = 'The deadline must be within the next ' . MAX_DEADLINE_DAYS . ' days.';
        if (!in_array($v['resume'], ['none', 'optional', 'required'], true)) $errors[] = 'Choose a resume requirement.';
        $selSkills = skills_from_post($pdo, $errors, SKILL_MAX_JOB);
        if (skills_ready($pdo) && !$selSkills) $errors[] = 'Choose at least one skill the job needs. Job seekers are matched to your job by these skills.';

        if (!$errors) {
            $data = ['title' => $v['title'], 'description' => $v['description'], 'responsibilities' => $v['responsibilities'], 'qualifications' => $v['qualifications'],
                     'work_address' => $v['address'], 'salary_min' => $smin, 'salary_max' => $smax, 'salary_period' => $v['period']];
            $flags = redflag_check($data);
            $manual = setting('post_mode', 'auto') === 'manual';
            $oldFlags = $job['red_flags'] ?? '';
            $held = $flags || $manual;
            if ($editing && !$reopening) {
                if ($job['status'] === 'rejected') $held = true;                       // admin decision: needs admin review
                if (strpos((string)$oldFlags, 'reported') !== false) $held = true;      // hidden by reports: needs admin review
            } elseif ($reopening && strpos((string)$oldFlags, 'reported') !== false) $held = true;
            $status = $held ? 'pending' : 'approved';
            $codes = $flags ? implode(',', array_keys($flags)) : null;
            if ($status === 'pending' && !$flags && !$manual && $editing && strpos((string)$oldFlags, 'reported') !== false) $codes = 'reported';
            if ($status === 'pending' && !$flags && $editing && $job['status'] === 'rejected') $codes = $oldFlags ?: null;

            $cols = [(int)$v['category'], $v['title'], $v['description'], $v['responsibilities'] ?: null, $v['qualifications'] ?: null, $v['type'],
                     $smin, $smax, $v['period'], (int)$v['barangay'], $v['address'] ?: null, (int)$v['slots'], $v['deadline'], $v['resume'],
                     $v['student'] ? 1 : 0, $v['schedule'] === 'weekends' ? 1 : 0, $v['schedule'], $status, $codes, $v['custom_category'] ?: null, $v['lat'], $v['lng']];
            if ($editing) {
                $pdo->prepare('UPDATE job_posts SET category_id=?, title=?, description=?, responsibilities=?, qualifications=?, employment_type=?, salary_min=?, salary_max=?,
                        salary_period=?, barangay_id=?, work_address=?, slots=?, deadline=?, resume_requirement=?, student_friendly=?, weekend_only=?, work_schedule=?, status=?, red_flags=?, custom_category=?, latitude=?, longitude=?,
                        rejection_reason=NULL, approved_at = CASE WHEN CAST(? AS text) = \'approved\' THEN COALESCE(approved_at, NOW()) ELSE NULL END WHERE id = ? AND employer_id = ?')
                    ->execute(array_merge($cols, [$status, $editId, $uid]));
                $jobId = $editId;
            } else {
                $pdo->prepare('INSERT INTO job_posts (employer_id, category_id, title, description, responsibilities, qualifications, employment_type, salary_min, salary_max,
                        salary_period, barangay_id, work_address, slots, deadline, resume_requirement, student_friendly, weekend_only, work_schedule, status, red_flags, custom_category, latitude, longitude, approved_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, ' . ($status === 'approved' ? 'NOW()' : 'NULL') . ')')
                    ->execute(array_merge([$uid], $cols));
                $jobId = (int)$pdo->lastInsertId();
                if ($status === 'approved') $pdo->prepare('UPDATE employer_profiles SET approved_post_count = approved_post_count + 1 WHERE user_id = ?')->execute([$uid]);
            }
            if (skills_ready($pdo)) skills_save($pdo, 'job_skills', $jobId, $selSkills);
            if ($status === 'approved') {
                flash('success', 'Your job is live. Job seekers can see it and apply now.');
            } else {
                notify_admins($pdo, 'Flagged post to review: ' . $v['title'], 'admin/job-review.php?id=' . $jobId . '&from=flagged');
                $why = $flags ? ' Reason: ' . implode(' ', array_map(fn($f) => $f['msg'], $flags)) : '';
                flash('info', 'Your job was saved but is held for a quick review by our team before it goes live.' . $why . ' You will be notified.');
            }
            redirect('employer/my-jobs.php');
        }
    }
}

$pageTitle = $editing ? ($reopening ? 'Renew job' : 'Edit job') : 'Post a job';
require __DIR__ . '/../includes/emp_top.php';
$todayStr = date('Y-m-d'); $maxStr = date('Y-m-d', strtotime('+' . MAX_DEADLINE_DAYS . ' days'));
?>
<?php
$pjSub = $reopening ? 'Check the details and set a new deadline to open this job again.'
       : ($editing ? 'Update your job details, then save. Posts with warning signs are held for a quick admin review.'
       : 'Clean posts go live right away. Posts with warning signs are held for a quick admin review.');
$pjActPct = $limits['limit'] > 0 ? min(100, (int)round($limits['active'] / $limits['limit'] * 100)) : 0;
$pjDayPct = DAILY_POST_LIMIT > 0 ? min(100, (int)round($limits['today'] / DAILY_POST_LIMIT * 100)) : 0;
?>
<header class="ep-head">
  <div><h1 class="dash-title ep-title"><?= e($pageTitle) ?></h1><p class="ep-sub"><?= e($pjSub) ?></p></div>
  <span class="ep-count"><b><?= (int)$limits['active'] ?></b> of <?= (int)$limits['limit'] ?> active posts</span>
</header>

<?php if ($block): ?>
  <div class="card empty"><h3><?= icon('lock', 26) ?> You can't post right now</h3><p><?= e($block) ?></p>
    <a class="btn btn-primary" href="<?= url($canPost ? 'employer/my-jobs.php' : 'employer/verification.php') ?>"><?= $canPost ? 'Go to my job posts' : 'Check verification' ?></a></div>
<?php else: ?>
<?php if ($errors): ?><div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($editing && !$reopening && $job['status'] === 'rejected' && $job['rejection_reason']): ?><div class="alert alert-info"><b>Why this was not approved:</b> <?= e($job['rejection_reason']) ?></div><?php endif; ?>

<div class="pj-grid pj-layout">
<form id="pj-form" method="post" class="form" novalidate>
  <?= csrf_field() ?>
  <section class="card panel-card pj-sec">
    <div class="pj-sec-head"><span class="pj-num">1</span><div><h2 class="pj-h">The job</h2><p>What is the work, and who are you looking for?</p></div></div>
    <div class="pj-body">
    <label for="pj-title">Job title</label>
    <input id="pj-title" name="title" maxlength="150" value="<?= e($v['title']) ?>" placeholder="Example: Store Cashier" required>
    <div class="row2">
      <div><label for="pj-cat">Category</label><select id="pj-cat" name="category" required><option value="">Choose category</option>
        <?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $v['category'] === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
        <div id="pj-other-wrap" style="display:none"><label for="pj-other">What kind of work?</label>
          <input id="pj-other" name="custom_category" maxlength="60" value="<?= e($v['custom_category']) ?>" placeholder="Example: Photographer"></div></div>
      <div><label for="pj-type">Employment type</label><select id="pj-type" name="type">
        <?php foreach ($types as $k => $l): ?><option value="<?= e($k) ?>" <?= $v['type'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    </div>
    <label id="pj-skills-lbl">Skills needed</label>
    <p class="small" style="margin:0 0 4px">Choose the skills a good applicant should have. Job seekers are matched to your job by these, and you will see how well each applicant fits.</p>
    <?php render_skill_picker($pdo, 'pj-skills', $selSkills, SKILL_MAX_CUSTOM, SKILL_MAX_JOB); ?>
    <label for="pj-desc">Job description</label>
    <textarea id="pj-desc" name="description" rows="5" maxlength="3000" placeholder="What is the job, and what will the person do each day?" required><?= e($v['description']) ?></textarea>
    <label for="pj-resp">Responsibilities (optional)</label>
    <textarea id="pj-resp" name="responsibilities" rows="3" maxlength="2000"><?= e($v['responsibilities']) ?></textarea>
    <label for="pj-qual">Qualifications and requirements (optional)</label>
    <textarea id="pj-qual" name="qualifications" rows="3" maxlength="2000"><?= e($v['qualifications']) ?></textarea>
    </div>
  </section>

  <section class="card panel-card pj-sec">
    <div class="pj-sec-head"><span class="pj-num">2</span><div><h2 class="pj-h">Pay and place</h2><p>How much it pays and exactly where the work is.</p></div></div>
    <div class="pj-body">
    <div class="pj-pay">
      <div><label for="pj-period">Salary</label><select id="pj-period" name="period">
        <?php foreach ($periods as $k => $l): ?><option value="<?= e($k) ?>" <?= $v['period'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div id="smin-wrap"><label for="pj-smin">Amount (₱)</label><input id="pj-smin" name="smin" inputmode="decimal" value="<?= e($v['smin']) ?>" placeholder="Example: 450"></div>
      <div id="smax-wrap"><label for="pj-smax">Up to (₱, optional)</label><input id="pj-smax" name="smax" inputmode="decimal" value="<?= e($v['smax']) ?>" placeholder="For a range"></div>
    </div>
    <div class="row2">
      <div><label for="pj-brgy">Barangay of the work</label><select id="pj-brgy" name="barangay" required><option value="">Choose barangay</option>
        <?php foreach ($barangays as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $v['barangay'] === (string)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select></div>
      <div><label for="pj-slots">Open slots</label><input id="pj-slots" name="slots" type="number" min="1" max="100" value="<?= e($v['slots']) ?>"></div>
    </div>
    <label for="pj-addr">Work address (optional)</label>
    <input id="pj-addr" name="address" maxlength="255" value="<?= e($v['address']) ?>" placeholder="Purok, street, or landmark">
    <label>Exact location on the map</label>
    <p class="small pj-maphelp">Click the map, or drag the pin, to the exact place where the work is. Job seekers will see this pin. Switch to Satellite to find your building.</p>
    <div id="pj-map" class="pj-map"></div>
    <input type="hidden" id="pj-lat" name="lat" value="<?= e($v['lat']) ?>"><input type="hidden" id="pj-lng" name="lng" value="<?= e($v['lng']) ?>">
    <div class="btn-row"><button class="btn btn-outline btn-sm" type="button" id="pj-locate">Use my current location</button>
      <span class="small" id="pj-pin-note"></span></div>
    </div>
  </section>

  <section class="card panel-card pj-sec">
    <div class="pj-sec-head"><span class="pj-num">3</span><div><h2 class="pj-h">Applying</h2><p>Deadline, resume, and who this job suits.</p></div></div>
    <div class="pj-body">
    <div class="row2">
      <div><label for="pj-deadline">Application deadline</label><input id="pj-deadline" name="deadline" type="date" min="<?= $todayStr ?>" max="<?= $maxStr ?>" value="<?= e($v['deadline']) ?>" required>
        <p class="small pj-hint">Up to <?= MAX_DEADLINE_DAYS ?> days from today.</p></div>
      <div><label for="pj-resume">Resume</label><select id="pj-resume" name="resume">
        <option value="none" <?= $v['resume'] === 'none' ? 'selected' : '' ?>>Not needed</option>
        <option value="optional" <?= $v['resume'] === 'optional' ? 'selected' : '' ?>>Optional</option>
        <option value="required" <?= $v['resume'] === 'required' ? 'selected' : '' ?>>Required</option></select></div>
    </div>
    <label for="pj-sched">Work schedule</label>
    <select id="pj-sched" name="schedule">
      <?php foreach (WORK_SCHEDULES as $k => $l): ?><option value="<?= e($k) ?>" <?= $v['schedule'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
    </select>
    <p class="small pj-hint">Weekdays is Monday to Friday. Weekends is Saturday and Sunday. Flexible means you and the worker agree on the hours.</p>
    <div class="pj-checks">
      <label class="check-row"><input id="pj-student" type="checkbox" name="student" value="1" <?= $v['student'] ? 'checked' : '' ?>> <span>Student-friendly</span></label>
    </div>
    <div class="notice pj-reminder"><b>Reminder:</b> never ask applicants to pay any fee. Jobs that do are removed and can lead to suspension.</div>
    </div>
  </section>
  <div class="pj-actions">
    <p class="small"><?= $editing ? 'Your changes replace the current post.' : 'You can edit or close this job any time.' ?></p>
    <div class="pj-actions-btns">
      <a class="btn btn-outline" href="<?= url('employer/my-jobs.php') ?>">Cancel</a>
      <button class="btn btn-primary pj-publish" type="submit"><?= $editing ? 'Save and publish' : 'Publish job' ?></button>
    </div>
  </div>
</form>

<aside class="pj-side">
  <p class="side-note">Live preview <span>updates as you type</span></p>
  <article class="card job">
    <div class="job-head"><div class="avatar" aria-hidden="true"><?= e(initials($emp['employer_name'])) ?></div>
      <div class="job-id"><h3 id="pv-title">Your job title</h3><p class="employer"><?= e($emp['employer_name']) ?></p></div></div>
    <div class="badges"><span class="badge badge-ver"><?= icon('shield', 14) ?> <?= e($vLabel) ?></span></div>
    <ul class="facts"><li><?= icon('pin') ?> <span id="pv-brgy">Barangay</span></li><li><?= icon('briefcase') ?> <span id="pv-type">Job type</span></li><li><?= icon('tag') ?> <span id="pv-cat">Category</span></li></ul>
    <div class="tags" id="pv-tags"></div>
    <div class="job-foot"><span class="salary" id="pv-salary">Negotiable</span><span class="deadline"><?= icon('calendar', 14) ?> <span id="pv-deadline">Apply by</span></span></div>
  </article>
  <div class="notice pj-warn" id="pv-warn" hidden><b>This post may be held for review.</b> It seems to mention:<ul style="margin:6px 0 0 18px"></ul><span class="small">You can still submit. Removing these phrases lets it go live right away.</span></div>
  <div class="card panel-card pj-limits">
    <h3>Your posting limits <span class="pj-plan"><?= $limits['premium'] ? 'Premium' : 'Free plan' ?></span></h3>
    <div class="lim"><div class="lim-row"><span>Active posts</span><b><?= (int)$limits['active'] ?> of <?= (int)$limits['limit'] ?></b></div>
      <div class="lim-bar<?= $pjActPct >= 80 ? ' warn' : '' ?>"><i style="width:<?= $pjActPct ?>%"></i></div></div>
    <div class="lim"><div class="lim-row"><span>New posts today</span><b><?= (int)$limits['today'] ?> of <?= (int)DAILY_POST_LIMIT ?></b></div>
      <div class="lim-bar<?= $pjDayPct >= 80 ? ' warn' : '' ?>"><i style="width:<?= $pjDayPct ?>%"></i></div></div>
    <a class="pj-upgrade" href="<?= url('employer/premium.php') ?>">Want a post to stand out? See Premium</a>
  </div>
  <div class="card panel-card pj-tips">
    <h3>Tips for a good post</h3>
    <ul class="ep-tips">
      <li>Use a clear title, like &ldquo;Store Cashier&rdquo;.</li>
      <li>Show the pay or a range. It gets more applicants.</li>
      <li>Drop the pin on the exact workplace.</li>
      <li>Never ask for fees, GCash, or bank details.</li>
    </ul>
  </div>
</aside>
</div>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function(){
  var latEl=document.getElementById('pj-lat'), lngEl=document.getElementById('pj-lng'), note=document.getElementById('pj-pin-note');
  var has=latEl.value!==''&&lngEl.value!=='';
  var start=has?[parseFloat(latEl.value),parseFloat(lngEl.value)]:[8.15,125.98];
  var map=L.map('pj-map').setView(start,has?17:13);
  var street=L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap'});
  var sat=L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',{maxZoom:19,attribution:'Imagery &copy; Esri'});
  street.addTo(map); L.control.layers({'Street':street,'Satellite':sat}).addTo(map);
  var marker=null;
  function put(lat,lng){
    if(!marker){marker=L.marker([lat,lng],{draggable:true}).addTo(map);marker.on('dragend',function(){var p=marker.getLatLng();put(p.lat,p.lng);});}
    else marker.setLatLng([lat,lng]);
    latEl.value=lat.toFixed(7); lngEl.value=lng.toFixed(7);
    note.textContent='Pin set ('+lat.toFixed(5)+', '+lng.toFixed(5)+')';
  }
  if(has) put(start[0],start[1]); else note.textContent='No pin yet. Click the map.';
  map.on('click',function(e){put(e.latlng.lat,e.latlng.lng);});
  document.getElementById('pj-locate').addEventListener('click',function(){
    if(!navigator.geolocation){note.textContent='Your browser cannot share your location.';return;}
    note.textContent='Finding you...';
    navigator.geolocation.getCurrentPosition(function(p){put(p.coords.latitude,p.coords.longitude);map.setView([p.coords.latitude,p.coords.longitude],18);},
      function(){note.textContent='Could not get your location. Click the map instead.';},{enableHighAccuracy:true,timeout:10000});
  });
})();
</script>
<script src="<?= url('assets/js/postjob.js') ?>"></script>
<script>
(function(){var c=document.getElementById('pj-cat'),w=document.getElementById('pj-other-wrap'),i=document.getElementById('pj-other');
function t(){var o=c.options[c.selectedIndex],on=o&&o.text.trim().toLowerCase()==='others';w.style.display=on?'block':'none';i.required=on;}
c.addEventListener('change',t);t();})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>