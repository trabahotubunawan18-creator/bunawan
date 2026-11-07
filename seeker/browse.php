<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require_role('seeker');
$pdo = db(); $uid = (int)$_SESSION['uid'];

// ---- Filters (same logic as the public Find Jobs page) ----
$types = ['full_time', 'part_time', 'temporary', 'internship'];
$search        = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$barangay = (int)($_GET['barangay'] ?? 0);
$category = (int)($_GET['category'] ?? 0);
$type     = in_array($_GET['type'] ?? '', $types, true) ? $_GET['type'] : '';
$smin     = max(0, (float)($_GET['salary_min'] ?? 0));
$student  = !empty($_GET['student']);
$schedule = (string)($_GET['schedule'] ?? '');
if ($schedule === '' && !empty($_GET['weekend'])) $schedule = 'weekends';   // old links
if ($schedule === 'any' || !isset(WORK_SCHEDULES[$schedule])) $schedule = '';
$noresume = !empty($_GET['noresume']);
$skill    = mb_substr(trim((string)($_GET['skill'] ?? '')), 0, 80);
$mine     = !empty($_GET['mine']);
$sort     = in_array($_GET['sort'] ?? '', ['oldest', 'deadline', 'match'], true) ? $_GET['sort'] : 'newest';
$page     = max(1, min(50, (int)($_GET['page'] ?? 1)));
$perPage  = 12;

$active = array_filter(['q' => $search, 'barangay' => $barangay, 'category' => $category, 'type' => $type,
    'salary_min' => $smin ?: '', 'student' => $student ? 1 : '', 'schedule' => $schedule,
    'noresume' => $noresume ? 1 : '', 'skill' => $skill, 'mine' => $mine ? 1 : '', 'sort' => $sort !== 'newest' ? $sort : ''], fn($v) => $v !== '' && $v !== 0);
$self = 'seeker/browse.php' . ($active ? '?' . http_build_query($active + ($page > 1 ? ['page' => $page] : [])) : '');

// ---- One-click Save / Unsave ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check() && ($_POST['action'] ?? '') === 'toggle_save') {
    $jid = (int)($_POST['job_id'] ?? 0);
    $has = $pdo->prepare('SELECT 1 FROM saved_jobs WHERE seeker_id = ? AND job_id = ?');
    $has->execute([$uid, $jid]);
    if ($has->fetchColumn()) {
        $pdo->prepare('DELETE FROM saved_jobs WHERE seeker_id = ? AND job_id = ?')->execute([$uid, $jid]);
        flash('success', 'Removed from your saved jobs.');
    } else {
        $pdo->prepare('INSERT INTO saved_jobs (seeker_id, job_id) VALUES (?, ?) ON CONFLICT DO NOTHING')->execute([$uid, $jid]);
        flash('success', 'Job saved. Find it under Saved jobs.');
    }
    redirect($self);
}

$where = ''; $p = [];
if ($search !== '') {
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $where .= ' AND (j.title ILIKE :q1 OR ep.employer_name ILIKE :q2 OR j.description ILIKE :q3 OR c.name ILIKE :q4 OR b.name ILIKE :q5)';
    foreach (['q1', 'q2', 'q3', 'q4', 'q5'] as $k) $p[$k] = $like;
}
if ($barangay) { $where .= ' AND j.barangay_id = :b'; $p['b'] = $barangay; }
if ($category) { $where .= ' AND j.category_id = :c'; $p['c'] = $category; }
if ($type)     { $where .= ' AND j.employment_type = :t'; $p['t'] = $type; }
if ($smin > 0) { $where .= " AND j.salary_period <> 'negotiable' AND COALESCE(j.salary_max, j.salary_min) >= :smin"; $p['smin'] = $smin; }
if ($student)  $where .= ' AND j.student_friendly = 1';
if ($schedule !== '') { $where .= ' AND j.work_schedule = :ws'; $p['ws'] = $schedule; }
if ($noresume) $where .= " AND j.resume_requirement = 'none'";
$myKeys = skill_list_map(seeker_skill_list($pdo, $uid));
if ($skill !== '') { $where .= ' AND EXISTS (SELECT 1 FROM job_skills jsf WHERE jsf.job_id = j.id AND jsf.skill_key = :sk)'; $p['sk'] = $skill; }
if ($mine) {   // only jobs that ask for at least one of my skills
    if ($myKeys) {
        $ph = [];
        foreach (array_keys($myKeys) as $i => $k) { $ph[] = ':mk' . $i; $p['mk' . $i] = (string)$k; }
        $where .= ' AND EXISTS (SELECT 1 FROM job_skills jsm WHERE jsm.job_id = j.id AND jsm.skill_key IN (' . implode(',', $ph) . '))';
    } else $where .= ' AND 1 = 0';
}

$orders = ['newest' => 'is_premium DESC, j.created_at DESC', 'oldest' => 'j.created_at ASC', 'deadline' => 'j.deadline ASC', 'match' => 'is_premium DESC, j.created_at DESC'];
$cnt = $pdo->prepare('SELECT COUNT(*) FROM (' . PUBLIC_JOB_SQL . $where . ') t');
$cnt->execute($p);
$total = (int)$cnt->fetchColumn();
$st = $pdo->prepare(PUBLIC_JOB_SQL . $where . ' ORDER BY ' . $orders[$sort] . ' LIMIT ' . ($sort === 'match' ? 200 : (int)($page * $perPage)));
$st->execute($p);
$jobs = $st->fetchAll();
attach_skill_info($pdo, $jobs, $myKeys);
if ($sort === 'match') {   // best match first, then my barangay, then Featured, then newest
    $mb = $pdo->prepare('SELECT barangay_id FROM seeker_profiles WHERE user_id = ?'); $mb->execute([$uid]);
    $jobs = array_slice(skill_sort_jobs($jobs, (int)$mb->fetchColumn() ?: null), 0, $page * $perPage);
}
$skillOpts = skills_in_use($pdo);

$savedIds = $pdo->prepare('SELECT job_id FROM saved_jobs WHERE seeker_id = ?');
$savedIds->execute([$uid]);
$savedSet = array_flip($savedIds->fetchAll(PDO::FETCH_COLUMN));

$barangays  = $pdo->query('SELECT id, name FROM barangays WHERE is_active = 1 ORDER BY name')->fetchAll();
$categories = $pdo->query('SELECT id, name FROM job_categories WHERE is_active = 1 ORDER BY sort_order')->fetchAll();
$moreUrl = 'seeker/browse.php?' . http_build_query($active + ['page' => $page + 1]);

$pageTitle = 'Browse Jobs';
require __DIR__ . '/../includes/dash_top.php';
?>
<div class="browse">
<header class="bj-head">
  <div>
    <h1 class="dash-title bj-title">Browse jobs</h1>
    <p class="bj-sub">Local vacancies in Bunawan. Save the ones you like, or apply right away.</p>
  </div>
  <span class="bj-count"><b><?= number_format($total) ?></b> <?= $total === 1 ? 'job' : 'jobs' ?> found<?= $search !== '' ? ' for “' . e($search) . '”' : '' ?></span>
</header>

<form method="get" action="<?= url('seeker/browse.php') ?>" class="card form filterbar bj-filters">
  <div class="bj-row">
    <div class="bj-search a-q"><label class="sr-only" for="b-q">Keyword</label><?= icon('search', 16) ?><input id="b-q" type="search" name="q" maxlength="100" value="<?= e($search) ?>" placeholder="Job title or company"></div>
    <div class="a-b"><label class="sr-only" for="b-b">Barangay</label><select id="b-b" name="barangay"><option value="">All barangays</option>
      <?php foreach ($barangays as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $barangay === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select></div>
    <div class="a-s"><label class="sr-only" for="b-sk">Skill</label><select id="b-sk" name="skill"><option value="">All skills</option>
      <?php foreach ($skillOpts as $so): ?><option value="<?= e($so['k']) ?>" <?= $skill === (string)$so['k'] ? 'selected' : '' ?>><?= e($so['label']) ?> (<?= (int)$so['n'] ?>)</option><?php endforeach; ?></select></div>
    <div class="a-c"><label class="sr-only" for="b-c">Category</label><select id="b-c" name="category"><option value="">All categories</option>
      <?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $category === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="a-t"><label class="sr-only" for="b-t">Type</label><select id="b-t" name="type"><option value="">Any type</option>
      <?php foreach ($types as $t): ?><option value="<?= e($t) ?>" <?= $type === $t ? 'selected' : '' ?>><?= e(type_label($t)) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-gold a-go" type="submit"><?= icon('search', 16) ?> Search jobs</button>
  </div>
  <div class="bj-row2">
    <label class="check-row chip-check"><input type="checkbox" name="student" value="1" <?= $student ? 'checked' : '' ?>> <span>Student-friendly</span></label>
    <label class="check-row chip-check"><input type="checkbox" name="noresume" value="1" <?= $noresume ? 'checked' : '' ?>> <span>No resume needed</span></label>
    <label class="check-row chip-check" title="Only jobs that ask for at least one of your skills"><input type="checkbox" name="mine" value="1" <?= $mine ? 'checked' : '' ?>> <span>Match my skills</span></label>
    <span class="bj-end">
      <span class="bj-money"><label class="sr-only" for="b-s">Minimum salary (₱)</label><span class="bj-peso" aria-hidden="true">₱</span><input id="b-s" type="number" name="salary_min" min="0" step="100" value="<?= $smin ? e($smin) : '' ?>" placeholder="Min. salary"></span>
      <span><label class="sr-only" for="b-w">Work schedule</label><select id="b-w" name="schedule"><option value="">Any schedule</option>
        <?php foreach (WORK_SCHEDULES as $k => $l): if ($k === 'any') continue; ?><option value="<?= e($k) ?>" <?= $schedule === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></span>
      <span><label class="sr-only" for="b-o">Sort by</label><select id="b-o" name="sort">
        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest first</option>
        <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
        <option value="deadline" <?= $sort === 'deadline' ? 'selected' : '' ?>>Nearest deadline</option>
        <option value="match" <?= $sort === 'match' ? 'selected' : '' ?>>Best skills match</option></select></span>
      <?php if ($active): ?><a class="bj-clear" href="<?= url('seeker/browse.php') ?>">Clear filters</a><?php endif; ?>
    </span>
  </div>
</form>

<?php if ($jobs): ?>
  <div class="browse-list">
    <?php foreach ($jobs as $j) render_job_card($j, (bool)$j['is_premium'], ['saved' => isset($savedSet[$j['id']]), 'action' => url($self)]); ?>
  </div>
  <?php if ($total > count($jobs)): ?>
    <p class="bj-more"><a class="btn btn-outline" href="<?= url($moreUrl) ?>">Load more jobs (<?= $total - count($jobs) ?> left)</a></p>
  <?php endif; ?>
<?php else: ?>
  <div class="card empty">
    <img src="<?= url('assets/img/empty-no-jobs.svg') ?>" alt="" width="150" onerror="this.style.display='none'">
    <h3>No jobs match your search</h3><p>Try fewer filters or a different keyword. New jobs are posted often.</p>
    <a class="btn btn-primary" href="<?= url('seeker/browse.php') ?>">Clear filters</a>
  </div>
<?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>