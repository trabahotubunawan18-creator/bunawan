<?php
$pageTitle = 'Find Jobs';
require __DIR__ . '/includes/header.php';
$pdo = db();

// ---- Read and clean the filters ----
$types = ['full_time', 'part_time', 'temporary', 'internship'];
$q        = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$barangay = (int)($_GET['barangay'] ?? 0);
$category = (int)($_GET['category'] ?? 0);
$type     = in_array($_GET['type'] ?? '', $types, true) ? $_GET['type'] : '';
$smin     = max(0, (float)($_GET['salary_min'] ?? 0));
$student  = !empty($_GET['student']);
$schedule = (string)($_GET['schedule'] ?? '');
if ($schedule === '' && !empty($_GET['weekend'])) $schedule = 'weekends';   // old links
if ($schedule === 'any' || !isset(WORK_SCHEDULES[$schedule])) $schedule = '';
$noresume = !empty($_GET['noresume']);
$isSeeker = (($_SESSION['role'] ?? '') === 'seeker');
$skill    = mb_substr(trim((string)($_GET['skill'] ?? '')), 0, 80);
$sort     = in_array($_GET['sort'] ?? '', $isSeeker ? ['oldest', 'deadline', 'match'] : ['oldest', 'deadline'], true) ? $_GET['sort'] : 'newest';
$page     = max(1, min(50, (int)($_GET['page'] ?? 1)));
$perPage  = 12;

$where = ''; $p = [];
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where .= ' AND (j.title ILIKE :q1 OR ep.employer_name ILIKE :q2 OR j.description ILIKE :q3 OR c.name ILIKE :q4 OR b.name ILIKE :q5)';
    foreach (['q1', 'q2', 'q3', 'q4', 'q5'] as $k) $p[$k] = $like;
}
if ($barangay) { $where .= ' AND j.barangay_id = :b';  $p['b'] = $barangay; }
if ($category) { $where .= ' AND j.category_id = :c';  $p['c'] = $category; }
if ($type)     { $where .= ' AND j.employment_type = :t'; $p['t'] = $type; }
if ($smin > 0) { $where .= " AND j.salary_period <> 'negotiable' AND COALESCE(j.salary_max, j.salary_min) >= :smin"; $p['smin'] = $smin; }
if ($student)  $where .= ' AND j.student_friendly = 1';
if ($schedule !== '') { $where .= ' AND j.work_schedule = :ws'; $p['ws'] = $schedule; }
if ($noresume) $where .= " AND j.resume_requirement = 'none'";
if ($skill !== '') { $where .= ' AND EXISTS (SELECT 1 FROM job_skills jsf WHERE jsf.job_id = j.id AND jsf.skill_key = :sk)'; $p['sk'] = $skill; }

$orders = [
    'newest'   => 'is_premium DESC, j.created_at DESC',
    'oldest'   => 'j.created_at ASC',
    'deadline' => 'j.deadline ASC',
    'match'    => 'is_premium DESC, j.created_at DESC',
];
$limit = $page * $perPage;

$cnt = $pdo->prepare('SELECT COUNT(*) FROM (' . PUBLIC_JOB_SQL . $where . ') t');
$cnt->execute($p);
$total = (int)$cnt->fetchColumn();

$st = $pdo->prepare(PUBLIC_JOB_SQL . $where . ' ORDER BY ' . $orders[$sort] . ' LIMIT ' . ($sort === 'match' ? 200 : (int)$limit));
$st->execute($p);
$jobs = $st->fetchAll();
// Seekers who are logged in also see how well each job matches their skills
$myKeys = $isSeeker ? skill_list_map(seeker_skill_list($pdo, (int)$_SESSION['uid'])) : [];
attach_skill_info($pdo, $jobs, $myKeys ?: null);
if ($sort === 'match') {
    $mb = $pdo->prepare('SELECT barangay_id FROM seeker_profiles WHERE user_id = ?'); $mb->execute([(int)$_SESSION['uid']]);
    $jobs = array_slice(skill_sort_jobs($jobs, (int)$mb->fetchColumn() ?: null), 0, $limit);
}
$skillOpts = skills_in_use($pdo);

$barangays  = $pdo->query('SELECT id, name FROM barangays WHERE is_active = 1 ORDER BY name')->fetchAll();
$categories = $pdo->query('SELECT id, name FROM job_categories WHERE is_active = 1 ORDER BY sort_order')->fetchAll();

$active = array_filter(['q' => $q, 'barangay' => $barangay, 'category' => $category, 'skill' => $skill, 'type' => $type,
    'salary_min' => $smin ?: '', 'student' => $student ? 1 : '', 'schedule' => $schedule,
    'noresume' => $noresume ? 1 : '', 'sort' => $sort !== 'newest' ? $sort : ''], fn($v) => $v !== '' && $v !== 0);
$moreUrl = 'jobs.php?' . http_build_query($active + ['page' => $page + 1]);
?>
<section class="page-hero">
  <div class="wrap">
    <h1>Find Jobs in Bunawan</h1>
    <p>Search local vacancies by title, company, keyword, or barangay.</p>
  </div>
</section>

<section class="wrap section" style="padding-top:32px">
  <div class="listing">
    <aside class="card filters" aria-label="Filters">
      <form method="get" action="<?= url('jobs.php') ?>" class="form">
        <label for="f-q">Job title, company, or keyword</label>
        <input id="f-q" type="search" name="q" maxlength="100" value="<?= e($q) ?>" placeholder="Example: cashier">

        <label for="f-b">Barangay</label>
        <select id="f-b" name="barangay"><option value="">All barangays</option>
          <?php foreach ($barangays as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $barangay === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
        </select>

        <label for="f-sk">Skills</label>
        <select id="f-sk" name="skill"><option value="">All skills</option>
          <?php foreach ($skillOpts as $so): ?><option value="<?= e($so['k']) ?>" <?= $skill === (string)$so['k'] ? 'selected' : '' ?>><?= e($so['label']) ?> (<?= (int)$so['n'] ?>)</option><?php endforeach; ?>
        </select>

        <label for="f-c">Category</label>
        <select id="f-c" name="category"><option value="">All categories</option>
          <?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $category === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
        </select>

        <label for="f-t">Employment type</label>
        <select id="f-t" name="type"><option value="">Any type</option>
          <?php foreach ($types as $t): ?><option value="<?= e($t) ?>" <?= $type === $t ? 'selected' : '' ?>><?= e(type_label($t)) ?></option><?php endforeach; ?>
        </select>

        <label for="f-w">Work schedule</label>
        <select id="f-w" name="schedule"><option value="">Any schedule</option>
          <?php foreach (WORK_SCHEDULES as $k => $l): if ($k === 'any') continue; ?><option value="<?= e($k) ?>" <?= $schedule === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select>

        <label for="f-s">Minimum salary (₱)</label>
        <input id="f-s" type="number" name="salary_min" min="0" step="100" value="<?= $smin ? e($smin) : '' ?>" placeholder="Example: 8000">
        <p class="small" style="margin:4px 0 0">Compares the amount as listed (per day, week, or month).</p>

        <label class="check-row"><input type="checkbox" name="student" value="1" <?= $student ? 'checked' : '' ?>> <span>Student-friendly</span></label>
        <label class="check-row"><input type="checkbox" name="noresume" value="1" <?= $noresume ? 'checked' : '' ?>> <span>No resume needed</span></label>

        <label for="f-sort">Sort by</label>
        <select id="f-sort" name="sort">
          <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest first</option>
          <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
          <option value="deadline" <?= $sort === 'deadline' ? 'selected' : '' ?>>Nearest deadline</option>
          <?php if ($isSeeker): ?><option value="match" <?= $sort === 'match' ? 'selected' : '' ?>>Best skills match</option><?php endif; ?>
        </select>

        <button class="btn btn-primary block" type="submit">Search jobs</button>
        <a class="btn btn-outline block" href="<?= url('jobs.php') ?>" style="margin-top:8px">Clear filters</a>
      </form>
    </aside>

    <div>
      <div class="results-bar">
        <p><b><?= number_format($total) ?></b> <?= $total === 1 ? 'job' : 'jobs' ?> found<?= $q !== '' ? ' for “' . e($q) . '”' : '' ?></p>
      </div>

      <?php if ($jobs): ?>
        <div class="grid grid-2"><?php foreach ($jobs as $j) render_job_card($j, (bool)$j['is_premium']); ?></div>
        <?php if ($total > count($jobs)): ?>
          <p class="center" style="margin-top:28px">
            <a class="btn btn-outline" href="<?= url($moreUrl) ?>">Load more jobs (<?= $total - count($jobs) ?> left)</a>
          </p>
        <?php endif; ?>
      <?php else: ?>
        <div class="card empty">
          <img src="<?= url('assets/img/empty-no-jobs.svg') ?>" alt="" width="160" onerror="this.style.display='none'">
          <h3>No jobs match your search</h3>
          <p>Try fewer filters or a different keyword, or check back soon as new jobs are posted often.</p>
          <a class="btn btn-primary" href="<?= url('jobs.php') ?>">Clear filters</a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>