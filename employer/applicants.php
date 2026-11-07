<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require_role('employer');
$pdo = db(); $uid = (int)$_SESSION['uid'];

$statuses = ['pending', 'reviewed', 'shortlisted', 'accepted', 'rejected', 'withdrawn'];
$tab = in_array($_GET['status'] ?? '', $statuses, true) ? $_GET['status'] : '';
$jobId = (int)($_GET['job'] ?? 0);
$search = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$sortBy = ($_GET['sort'] ?? '') === 'match' ? 'match' : 'recent';
$page = max(1, (int)($_GET['page'] ?? 1)); $per = 10;

$keep = array_filter(['status' => $tab, 'job' => $jobId ?: '', 'q' => $search, 'sort' => $sortBy === 'match' ? 'match' : '', 'page' => $page > 1 ? $page : '']);
$back = 'employer/applicants.php' . ($keep ? '?' . http_build_query($keep) : '');

// ---------- Change an applicant's status ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { flash('error', 'Your session expired. Please try again.'); redirect($back); }
    $appId = (int)($_POST['id'] ?? 0);
    $to = $_POST['to'] ?? '';
    $msg = mb_substr(trim($_POST['message'] ?? ''), 0, 255);
    // Only your own jobs' applicants, and never a withdrawn application
    $s = $pdo->prepare('SELECT a.id, a.status, a.seeker_id, a.applicant_name, j.title FROM job_applications a JOIN job_posts j ON j.id = a.job_id WHERE a.id = ? AND j.employer_id = ?');
    $s->execute([$appId, $uid]);
    $app = $s->fetch();
    if (!$app) flash('error', 'That application was not found.');
    elseif ($app['status'] === 'withdrawn') flash('error', 'The applicant withdrew this application, so it cannot be changed.');
    elseif (!in_array($to, ['reviewed', 'shortlisted', 'accepted', 'rejected'], true)) flash('error', 'Choose a valid status.');
    elseif ($app['status'] === $to) flash('info', 'The applicant already has that status.');
    else {
        $pdo->prepare('UPDATE job_applications SET status = ?, employer_message = ? WHERE id = ?')->execute([$to, $msg !== '' ? $msg : null, $appId]);
        $text = ['reviewed' => 'is being reviewed', 'shortlisted' => 'was shortlisted', 'accepted' => 'was accepted. Congratulations!', 'rejected' => 'was not selected this time'][$to];
        $pdo->prepare('INSERT INTO notifications (user_id, message, link) VALUES (?,?,?)')
            ->execute([$app['seeker_id'], 'Your application for "' . mb_substr($app['title'], 0, 80) . '" ' . $text, 'seeker/applications.php']);
        flash('success', $app['applicant_name'] . ' is now marked as ' . app_status($to)[0] . '. They were notified.');
    }
    redirect($back);
}

// ---------- Filters ----------
$myJobs = $pdo->prepare('SELECT j.id, j.title, (SELECT COUNT(*) FROM job_applications a WHERE a.job_id = j.id) AS n FROM job_posts j WHERE j.employer_id = ? AND j.status <> \'removed\' ORDER BY j.created_at DESC');
$myJobs->execute([$uid]);
$myJobs = array_values(array_filter($myJobs->fetchAll(), fn($j) => $j['n'] > 0 || (int)$j['id'] === $jobId));

$where = ' WHERE j.employer_id = :u'; $p = ['u' => $uid];
if ($jobId) { $where .= ' AND a.job_id = :j'; $p['j'] = $jobId; }
if ($search !== '') {
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $where .= ' AND (a.applicant_name ILIKE :q1 OR a.applicant_phone ILIKE :q2)'; $p['q1'] = $like; $p['q2'] = $like;
}
$from = ' FROM job_applications a JOIN job_posts j ON j.id = a.job_id';

// Tab counts follow the job and search filters
$cs = $pdo->prepare('SELECT a.status, COUNT(*) AS c' . $from . $where . ' GROUP BY a.status');
$cs->execute($p);
$count = array_fill_keys($statuses, 0);
foreach ($cs as $r) $count[$r['status']] = (int)$r['c'];
$count['all'] = array_sum($count);

$w2 = $where; $p2 = $p;
if ($tab) { $w2 .= ' AND a.status = :s'; $p2['s'] = $tab; }
$total = $count[$tab ?: 'all'];
$r = $pdo->prepare('SELECT a.id, a.seeker_id, a.applicant_name, a.applicant_phone, a.message, a.resume_path, a.status, a.employer_message, a.applied_at,
        j.id AS job_id, j.title, u.email, sp.education, sp.skills, sp.work_experience, sp.availability, b.name AS barangay'
    . $from . ' JOIN users u ON u.id = a.seeker_id LEFT JOIN seeker_profiles sp ON sp.user_id = a.seeker_id LEFT JOIN barangays b ON b.id = sp.barangay_id'
    . $w2 . " ORDER BY a.status = 'pending' DESC, a.applied_at DESC" . ($sortBy === 'match' ? ' LIMIT 300' : " LIMIT $per OFFSET " . (($page - 1) * $per)));
$r->execute($p2);
$rows = $r->fetchAll();

// Skills: what each job needs, what each applicant chose when applying (snapshot), and the match between them
$jobNeed = job_skill_map($pdo, array_column($rows, 'job_id'));
$snap = [];
if ($rows && skills_ready($pdo)) {
    $ids = array_map('intval', array_column($rows, 'id'));
    $q = $pdo->prepare('SELECT application_id, skill_key, label FROM application_skills WHERE application_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY label');
    $q->execute($ids);
    foreach ($q as $sr) $snap[(int)$sr['application_id']][(string)$sr['skill_key']] = $sr['label'];
}
foreach ($rows as &$a) {
    $a['need']  = $jobNeed[(int)$a['job_id']] ?? [];
    $a['have']  = $snap[(int)$a['id']] ?? skill_list_map(seeker_skill_list($pdo, (int)$a['seeker_id']));   // older applications use the current profile
    $a['match'] = $a['need'] ? skill_match(array_keys($a['have']), $a['need']) : null;
}
unset($a);
if ($sortBy === 'match') {
    usort($rows, fn($x, $y) => ((int)($y['match']['pct'] ?? -1) <=> (int)($x['match']['pct'] ?? -1)) ?: strcmp((string)$y['applied_at'], (string)$x['applied_at']));
    $rows = array_slice($rows, ($page - 1) * $per, $per);
}

$base = url('employer/applicants.php?' . http_build_query(array_filter(['status' => $tab, 'job' => $jobId ?: '', 'q' => $search, 'sort' => $sortBy === 'match' ? 'match' : ''])));
$tabUrl = fn($s) => url('employer/applicants.php?' . http_build_query(array_filter(['status' => $s, 'job' => $jobId ?: '', 'q' => $search, 'sort' => $sortBy === 'match' ? 'match' : ''])));

$pageTitle = 'Applicants';
require __DIR__ . '/../includes/emp_top.php';
?>
<header class="ep-head">
  <div><h1 class="dash-title ep-title">Applicants</h1><p class="ep-sub">Review applications, download resumes, and update each applicant's status. Applicants are notified when you change it.</p></div>
  <span class="ep-count"><b><?= (int)$count['all'] ?></b> <?= (int)$count['all'] === 1 ? 'applicant' : 'applicants' ?></span>
</header>

<div class="ep-filters">
<form method="get" class="form ep-search">
  <?php if ($tab): ?><input type="hidden" name="status" value="<?= e($tab) ?>"><?php endif; ?>
  <select name="job" aria-label="Filter by job" onchange="this.form.submit()">
    <option value="">All jobs</option>
    <?php foreach ($myJobs as $j): ?><option value="<?= (int)$j['id'] ?>" <?= $jobId === (int)$j['id'] ? 'selected' : '' ?>><?= e($j['title']) ?> (<?= (int)$j['n'] ?>)</option><?php endforeach; ?>
  </select>
  <select name="sort" aria-label="Sort applicants" onchange="this.form.submit()">
    <option value="">Newest first</option>
    <option value="match" <?= $sortBy === 'match' ? 'selected' : '' ?>>Best skills match</option>
  </select>
  <input name="q" type="search" maxlength="100" placeholder="Search name or phone" value="<?= e($search) ?>" aria-label="Search applicants">
  <button class="btn btn-primary btn-sm" type="submit">Search</button>
  <?php if ($search !== '' || $jobId): ?><a class="btn btn-outline btn-sm" href="<?= url('employer/applicants.php' . ($tab ? '?status=' . $tab : '')) ?>">Clear</a><?php endif; ?>
</form>

<div class="filter-tabs">
  <a class="<?= $tab === '' ? 'on' : '' ?>" href="<?= e($tabUrl('')) ?>">All (<?= $count['all'] ?>)</a>
  <?php foreach ($statuses as $st): ?><a class="<?= $tab === $st ? 'on' : '' ?>" href="<?= e($tabUrl($st)) ?>"><?= e(app_status($st)[0]) ?> (<?= $count[$st] ?>)</a><?php endforeach; ?>
</div>
</div>

<?php if (!$rows): ?>
  <div class="card empty"><h3>No applicants here</h3><p><?= $count['all'] === 0 && !$search && !$jobId ? 'When job seekers apply for your jobs, they will show up here.' : 'Try a different tab, job, or search.' ?></p>
    <a class="btn btn-primary" href="<?= url('employer/my-jobs.php') ?>">My job posts</a></div>
<?php else: foreach ($rows as $a): [$lbl, $cls] = app_status($a['status']); $hasProfile = $a['education'] || $a['skills'] || $a['work_experience'] || $a['availability'] || $a['barangay']; ?>
  <div class="card panel-card applicant<?= $a['status'] === 'pending' ? ' applicant-new' : '' ?>">
    <div class="row-item card-head">
      <div style="display:flex;gap:14px;align-items:center">
        <span class="av"><?= e(initials($a['applicant_name'])) ?></span>
        <div><b style="font-size:17px"><?= e($a['applicant_name']) ?></b>
          <div class="small">For <a href="<?= url('employer/applicants.php?job=' . (int)$a['job_id']) ?>"><?= e($a['title']) ?></a> &middot; applied <?= e(date('M j, Y g:i A', strtotime($a['applied_at']))) ?></div></div>
      </div>
      <span style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <?php if ($a['match']): ?><span class="skm-pct <?= e(skill_badge_class($a['match']['pct'])) ?>" title="<?= count($a['match']['matched']) ?> of <?= (int)$a['match']['need'] ?> required skills"><?= (int)$a['match']['pct'] ?>% match</span><?php endif; ?>
        <span class="st <?= e($cls) ?>"><?= e($lbl) ?></span>
      </span>
    </div>

    <div class="contact-line">
      <span><?= icon('send', 14) ?> <a href="tel:<?= e($a['applicant_phone']) ?>"><?= e($a['applicant_phone']) ?></a></span>
      <span><?= icon('user', 14) ?> <a href="mailto:<?= e($a['email']) ?>"><?= e($a['email']) ?></a></span>
      <?php if ($a['resume_path']): ?><a class="btn btn-outline btn-sm" href="<?= url('employer/resume-download.php?id=' . (int)$a['id']) ?>"><?= icon('file', 14) ?> Download resume</a>
      <?php else: ?><span class="small">No resume attached</span><?php endif; ?>
    </div>

    <?php if ($a['need'] || $a['have']): ?>
      <div class="applicant-skills" style="margin:12px 0 0">
        <div class="skm-head"><span class="small"><b>Skills</b><?= $a['match'] ? ' compared with what this job needs' : ' chosen by the applicant' ?></span></div>
        <div class="skm">
          <?php foreach ($a['need'] as $nk => $nl): $hit = isset($a['have'][$nk]); ?>
            <span class="skm-pill <?= $hit ? 'is-hit' : 'is-miss' ?>" title="<?= $hit ? 'Applicant has this skill' : 'Applicant did not list this skill' ?>"><?= $hit ? icon('check', 13) : '' ?> <?= e($nl) ?></span>
          <?php endforeach; ?>
          <?php foreach ($a['have'] as $hk => $hl): if (isset($a['need'][$hk])) continue; ?><span class="skm-pill"><?= e($hl) ?></span><?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($a['message']): ?><p class="note-box" style="background:var(--sage)"><b>Message from the applicant:</b> <?= nl2br(e($a['message'])) ?></p><?php endif; ?>

    <?php if ($hasProfile): ?>
      <details class="applicant-profile"><summary>View profile</summary>
        <dl class="kv">
          <?php if ($a['barangay']): ?><dt>Barangay</dt><dd><?= e($a['barangay']) ?></dd><?php endif; ?>
          <?php if ($a['education']): ?><dt>Education</dt><dd><?= nl2br(e($a['education'])) ?></dd><?php endif; ?>
          <?php if ($a['skills']): ?><dt>Skills</dt><dd><?= nl2br(e($a['skills'])) ?></dd><?php endif; ?>
          <?php if ($a['work_experience']): ?><dt>Experience</dt><dd><?= nl2br(e($a['work_experience'])) ?></dd><?php endif; ?>
          <?php if ($a['availability']): ?><dt>Availability</dt><dd><?= nl2br(e($a['availability'])) ?></dd><?php endif; ?>
        </dl>
      </details>
    <?php endif; ?>

    <?php if ($a['employer_message'] && $a['status'] !== 'pending'): ?><p class="small" style="margin:10px 0 0">Your note to the applicant: <i><?= e($a['employer_message']) ?></i></p><?php endif; ?>

    <?php if ($a['status'] === 'withdrawn'): ?>
      <p class="small" style="margin:12px 0 0">The applicant withdrew this application.</p>
    <?php else: ?>
      <form method="post" class="form action-form" action="<?= e(url($back)) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
        <label for="m<?= (int)$a['id'] ?>">Note to the applicant (optional, they will see it)</label>
        <textarea id="m<?= (int)$a['id'] ?>" name="message" rows="2" maxlength="255" placeholder="Example: Please come to our shop on Monday at 9 AM."></textarea>
        <p class="small" style="margin:0 0 8px">When you shortlist or accept an applicant, they can see your contact name and mobile number.</p>
        <div class="btn-row">
          <?php if ($a['status'] === 'pending'): ?><button class="btn btn-outline btn-sm" name="to" value="reviewed">Mark reviewed</button><?php endif; ?>
          <?php if ($a['status'] !== 'shortlisted'): ?><button class="btn btn-outline btn-sm" name="to" value="shortlisted">Shortlist</button><?php endif; ?>
          <?php if ($a['status'] !== 'accepted'): ?><button class="btn btn-primary btn-sm" name="to" value="accepted" data-confirm="This applicant will be accepted." data-confirm-title="Accept applicant?" data-confirm-ok="Accept" data-confirm-tone="info">Accept</button><?php endif; ?>
          <?php if ($a['status'] !== 'rejected'): ?><button class="btn btn-danger btn-sm" name="to" value="rejected" data-confirm="This applicant will be rejected." data-confirm-title="Reject applicant?" data-confirm-ok="Reject" data-confirm-tone="danger">Reject</button><?php endif; ?>
        </div>
      </form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
  <?= pager($total, $page, $per, $base) ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>