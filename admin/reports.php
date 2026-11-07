<?php
require __DIR__ . '/../includes/admin_auth.php';
require_admin();
$pdo = db();

// ---------- Date range ----------
$ranges = ['30' => 'Last 30 days', '90' => 'Last 3 months', '365' => 'Last 12 months', 'all' => 'All time', 'custom' => 'Custom'];
$valid = fn($d) => ($x = DateTime::createFromFormat('Y-m-d', (string)$d)) && $x->format('Y-m-d') === $d;
$range = array_key_exists($_GET['range'] ?? '', $ranges) ? $_GET['range'] : '30';
$today = date('Y-m-d');
if ($range === 'custom') {
    $from = $valid($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d', strtotime('-30 days'));
    $to = $valid($_GET['to'] ?? '') ? $_GET['to'] : $today;
    if ($from > $to) [$from, $to] = [$to, $from];
    if ($to > $today) $to = $today;
} elseif ($range === 'all') {
    $from = '2000-01-01'; $to = $today;
} else {
    $from = date('Y-m-d', strtotime('-' . (int)$range . ' days')); $to = $today;
}
$P = [$from, date('Y-m-d', strtotime($to . ' +1 day'))];   // [start inclusive, end exclusive]
$periodLabel = $range === 'all' ? 'All time' : date('M j, Y', strtotime($from)) . ' - ' . date('M j, Y', strtotime($to));

$one = function (string $sql, array $p = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); };
$all = function (string $sql, array $p = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); };

// ---------- Summary numbers ----------
$newSeekers   = (int)$one("SELECT COUNT(*) FROM users WHERE role = 'seeker' AND created_at >= ? AND created_at < ?", $P);
$newEmployers = (int)$one("SELECT COUNT(*) FROM users WHERE role = 'employer' AND created_at >= ? AND created_at < ?", $P);
$jobsPosted   = (int)$one('SELECT COUNT(*) FROM job_posts WHERE created_at >= ? AND created_at < ?', $P);
$apps         = (int)$one('SELECT COUNT(*) FROM job_applications WHERE applied_at >= ? AND applied_at < ?', $P);
$accepted     = (int)$one("SELECT COUNT(*) FROM job_applications WHERE status = 'accepted' AND applied_at >= ? AND applied_at < ?", $P);
$verifiedN    = (int)$one('SELECT COUNT(*) FROM employer_profiles WHERE verified_at >= ? AND verified_at < ?', $P);
$avgVerify    = $one('SELECT AVG(FLOOR(EXTRACT(EPOCH FROM (verified_at - created_at)) / 3600)) / 24 FROM employer_profiles WHERE verified_at >= ? AND verified_at < ?', $P);
$reportsN     = (int)$one('SELECT COUNT(*) FROM job_reports WHERE created_at >= ? AND created_at < ?', $P);
$activeJobsNow = (int)$one("SELECT COUNT(*) FROM job_posts WHERE status = 'approved' AND deadline >= CURRENT_DATE");
$views        = (int)$one('SELECT COALESCE(SUM(views),0) FROM job_posts WHERE created_at >= ? AND created_at < ?', $P);

// Complaints breakdown
$reasonLabel = ['scam' => 'Looks like a scam', 'asks_payment' => 'Asks for payment', 'wrong_info' => 'Wrong information', 'discrimination' => 'Discrimination', 'other' => 'Other'];
$cStatusLabel = ['open' => 'Open', 'action_taken' => 'Action taken', 'dismissed' => 'Dismissed'];
$byReason = []; $byCStatus = [];
foreach ($all('SELECT reason, COUNT(*) AS c FROM job_reports WHERE created_at >= ? AND created_at < ? GROUP BY reason ORDER BY c DESC', $P) as $r) $byReason[$r['reason']] = (int)$r['c'];
foreach ($all('SELECT status, COUNT(*) AS c FROM job_reports WHERE created_at >= ? AND created_at < ? GROUP BY status', $P) as $r) $byCStatus[$r['status']] = (int)$r['c'];
$flaggedEmp = $all("SELECT ep.employer_name AS label, COUNT(r.id) AS n, COUNT(DISTINCT j.id) AS jobs FROM job_reports r
    JOIN job_posts j ON j.id = r.job_id JOIN employer_profiles ep ON ep.user_id = j.employer_id
    WHERE r.created_at >= ? AND r.created_at < ? GROUP BY ep.user_id, ep.employer_name ORDER BY n DESC LIMIT 5", $P);
$rate = $apps > 0 ? round($accepted / $apps * 100) : 0;
$avgTxt = ($avgVerify !== null && $avgVerify !== false) ? number_format((float)$avgVerify, 1) : '-';

$summary = [
    ['New job seekers', $newSeekers], ['New employers', $newEmployers], ['Jobs posted', $jobsPosted],
    ['Applications received', $apps], ['Accepted applicants', $accepted], ['Acceptance rate', $rate . '%'],
    ['Employers verified', $verifiedN], ['Average days to verify an employer', $avgTxt], ['Complaints received', $reportsN],
];

// ---------- Tables ----------
$byBrg = $all('SELECT b.name AS label, COUNT(DISTINCT j.id) AS jobs, COUNT(a.id) AS apps FROM barangays b
    LEFT JOIN job_posts j ON j.barangay_id = b.id AND j.created_at >= ? AND j.created_at < ?
    LEFT JOIN job_applications a ON a.job_id = j.id
    WHERE b.is_active = 1 GROUP BY b.id, b.name ORDER BY jobs DESC, apps DESC, b.name', $P);
$byCat = $all('SELECT c.name AS label, COUNT(DISTINCT j.id) AS jobs, COUNT(a.id) AS apps FROM job_categories c
    LEFT JOIN job_posts j ON j.category_id = c.id AND j.created_at >= ? AND j.created_at < ?
    LEFT JOIN job_applications a ON a.job_id = j.id
    WHERE c.is_active = 1 GROUP BY c.id, c.name ORDER BY jobs DESC, apps DESC, c.sort_order', $P);
$byType = $all('SELECT j.employment_type AS label, COUNT(DISTINCT j.id) AS jobs, COUNT(a.id) AS apps FROM job_posts j
    LEFT JOIN job_applications a ON a.job_id = j.id WHERE j.created_at >= ? AND j.created_at < ?
    GROUP BY j.employment_type ORDER BY jobs DESC', $P);
$top = $all("SELECT ep.employer_name AS label, COUNT(DISTINCT j.id) AS jobs, COUNT(a.id) AS apps, COALESCE(SUM(CASE WHEN a.status = 'accepted' THEN 1 ELSE 0 END), 0) AS hired
    FROM employer_profiles ep JOIN job_posts j ON j.employer_id = ep.user_id AND j.created_at >= ? AND j.created_at < ?
    LEFT JOIN job_applications a ON a.job_id = j.id GROUP BY ep.user_id, ep.employer_name ORDER BY apps DESC, jobs DESC LIMIT 5", $P);
$outcomes = [];
foreach ($all('SELECT status, COUNT(*) AS c FROM job_applications WHERE applied_at >= ? AND applied_at < ? GROUP BY status', $P) as $r) $outcomes[$r['status']] = (int)$r['c'];

// Month by month (latest 12 months inside the range)
$months = [];
$cur = new DateTime(date('Y-m-01', strtotime($to))); $start = new DateTime(date('Y-m-01', strtotime($from)));
for ($i = 0; $i < 12 && $cur >= $start; $i++) {
    $months[$cur->format('Y-m')] = ['label' => $cur->format('M Y'), 'seekers' => 0, 'employers' => 0, 'jobs' => 0, 'apps' => 0];
    $cur->modify('-1 month');
}
foreach ($all("SELECT TO_CHAR(created_at, 'YYYY-MM') AS m, role, COUNT(*) AS c FROM users WHERE role IN ('seeker','employer') AND created_at >= ? AND created_at < ? GROUP BY m, role", $P) as $r)
    if (isset($months[$r['m']])) $months[$r['m']][$r['role'] . 's'] = (int)$r['c'];
foreach ($all("SELECT TO_CHAR(created_at, 'YYYY-MM') AS m, COUNT(*) AS c FROM job_posts WHERE created_at >= ? AND created_at < ? GROUP BY m", $P) as $r)
    if (isset($months[$r['m']])) $months[$r['m']]['jobs'] = (int)$r['c'];
foreach ($all("SELECT TO_CHAR(applied_at, 'YYYY-MM') AS m, COUNT(*) AS c FROM job_applications WHERE applied_at >= ? AND applied_at < ? GROUP BY m", $P) as $r)
    if (isset($months[$r['m']])) $months[$r['m']]['apps'] = (int)$r['c'];
$months = array_reverse($months, true);

$num = fn(array $rows, array $keys) => array_map(fn($r) => array_merge([$r['label']], array_map(fn($k) => (int)$r[$k], $keys)), $rows);
$tables = [
    'barangay' => ['Jobs and applications by barangay', ['Barangay', 'Jobs posted', 'Applications'], $num($byBrg, ['jobs', 'apps'])],
    'category' => ['Jobs and applications by category', ['Category', 'Jobs posted', 'Applications'], $num($byCat, ['jobs', 'apps'])],
    'type'     => ['By employment type', ['Type', 'Jobs posted', 'Applications'],
                   array_map(fn($r) => [type_label($r['label']), (int)$r['jobs'], (int)$r['apps']], $byType)],
    'top'      => ['Top employers by applicants', ['Employer', 'Jobs', 'Applicants', 'Accepted'], $num($top, ['jobs', 'apps', 'hired'])],
    'reasons'  => ['Complaints by reason', ['Reason', 'Complaints'], array_map(fn($k, $v) => [$reasonLabel[$k] ?? $k, $v], array_keys($byReason), array_values($byReason))],
    'cstatus'  => ['Complaint outcomes', ['Status', 'Complaints'], array_map(fn($k, $v) => [$cStatusLabel[$k] ?? $k, $v], array_keys($byCStatus), array_values($byCStatus))],
    'flagemp'  => ['Employers with the most complaints', ['Employer', 'Complaints', 'Jobs complained about'], $num($flaggedEmp, ['n', 'jobs'])],
    'monthly'  => ['Month by month', ['Month', 'New seekers', 'New employers', 'Jobs posted', 'Applications'],
                   array_map(fn($m) => [$m['label'], $m['seekers'], $m['employers'], $m['jobs'], $m['apps']], array_values($months))],
];

// ---------- Export: header details shared by Print, Save as PDF and CSV ----------
require_once __DIR__ . '/../includes/admin_export.php';
$xp = ['title' => 'Reports and Analytics', 'slug' => 'report_' . $from . '_to_' . $to,
    'purpose' => 'Summary of job seekers, employers, job posts, applications, hiring results and complaints for the period covered.',
    'filters' => ['Period' => $periodLabel], 'filters_label' => 'Period covered', 'count' => null, 'log' => 'report_exported'];
// Tables for the PDF file (same content as the CSV)
$xpSections = [['title' => 'Summary', 'head' => ['Measure', 'Value'], 'rows' => array_map(fn($r) => [$r[0], $r[1] === '-' ? 'N/A' : $r[1]], $summary), 'right' => [1]]];
if ($outcomes) {
    $xSum = array_sum($outcomes); $xRows = [];
    foreach (array_unique(array_merge(['pending', 'reviewed', 'shortlisted', 'accepted', 'rejected', 'withdrawn'], array_keys($outcomes))) as $st)
        if (!empty($outcomes[$st])) $xRows[] = [app_status($st)[0], $outcomes[$st], round($outcomes[$st] / $xSum * 100) . '%'];
    $xpSections[] = ['title' => 'Application outcomes', 'head' => ['Status', 'Applications', 'Share of total'], 'rows' => $xRows, 'total' => ['Total', $xSum, '100%'], 'right' => [1, 2]];
}
foreach ($tables as $xKey => [$xTitle, $xHead, $xRows]) {
    $xTot = null;
    if (in_array($xKey, ['barangay', 'category', 'type', 'reasons', 'cstatus', 'monthly'], true) && count($xRows) > 1) {
        $xTot = ['Total'];
        for ($i = 1; $i < count($xHead); $i++) $xTot[] = array_sum(array_column($xRows, $i));
    }
    $xpSections[] = ['title' => $xTitle, 'head' => $xHead, 'rows' => $xRows, 'total' => $xTot, 'right' => range(1, count($xHead) - 1)];
}
$xp['sections'] = $xpSections;
$xpTitle = XP_NAME . ' - Report ' . $from . ' to ' . $to;

xp_log_hit($xp);

// ---------- CSV export ----------
if (!empty($_GET['export'])) {
    $safe = fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v;   // stops spreadsheet formulas
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="trabaho-tu-bunawan-report_' . $from . '_to_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // lets Excel read the file as UTF-8
    $w = function (array $row = []) use ($out, $safe) { fputcsv($out, array_map($safe, $row), ',', '"', '\\', "\r\n"); };
    $section = function (string $title, array $head) use ($w) { $w(); $w([strtoupper($title)]); $w($head); };

    xp_csv_intro($w, $xp);

    $section('Summary', ['Measure', 'Value']);
    foreach ($summary as [$label, $val]) $w([$label, $val === '-' ? 'N/A' : $val]);

    $section('Application outcomes', ['Status', 'Applications', 'Share of total']);
    if (!$outcomes) { $w(['No applications in this period.']); }
    else {
        $order = array_unique(array_merge(['pending', 'reviewed', 'shortlisted', 'accepted', 'rejected', 'withdrawn'], array_keys($outcomes)));
        $sum = array_sum($outcomes);
        foreach ($order as $st) if (!empty($outcomes[$st])) $w([app_status($st)[0], $outcomes[$st], round($outcomes[$st] / $sum * 100) . '%']);
        $w(['Total', $sum, '100%']);
    }

    $withTotal = ['barangay', 'category', 'type', 'reasons', 'cstatus', 'monthly'];
    foreach ($tables as $key => [$title, $head, $rows]) {
        $section($title, $head);
        if (!$rows) { $w(['No data in this period.']); continue; }
        foreach ($rows as $r) $w($r);
        if (in_array($key, $withTotal, true) && count($rows) > 1) {
            $t = ['Total'];
            for ($i = 1; $i < count($head); $i++) $t[] = array_sum(array_column($rows, $i));
            $w($t);
        }
    }
    $w();
    $w(['End of report']);
    log_admin('report_exported', 'report', null, $periodLabel);
    exit;
}

function a_table(array $t, string $ic = 'list', bool $bars = true): void { [$title, $head, $rows] = $t;
    $mx = 1; if ($bars) foreach ($rows as $r) $mx = max($mx, (int)($r[1] ?? 0)); ?>
  <section class="rp-card">
    <header class="rp-card-head"><span class="rp-ico"><?= icon($ic, 18) ?></span><h2><?= e($title) ?></h2><?php if ($rows): ?><span class="rp-count"><?= count($rows) ?></span><?php endif; ?></header>
    <?php if (!$rows): ?><p class="rp-none">No data in this period.</p><?php else: ?>
    <div class="rp-scroll"><table class="rp-tbl"><thead><tr>
      <?php foreach ($head as $i => $h): ?><th class="<?= $i ? 'rp-n' : '' ?>"><?= e($h) ?></th><?php endforeach; ?></tr></thead><tbody>
      <?php foreach ($rows as $r): ?><tr><?php foreach ($r as $i => $v): ?>
        <?php if (!$i): ?><td><b><?= e($v) ?></b></td>
        <?php elseif ($i === 1 && $bars): ?><td class="rp-n"><span class="rp-cellbar"><span class="rp-mini"><i style="width:<?= round((int)$v / $mx * 100) ?>%"></i></span><?= e(number_format((int)$v)) ?></span></td>
        <?php else: ?><td class="rp-n"><?= e(number_format((int)$v)) ?></td><?php endif; ?>
      <?php endforeach; ?></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </section>
<?php }

$qs = http_build_query(array_filter(['range' => $range, 'from' => $range === 'custom' ? $from : '', 'to' => $range === 'custom' ? $to : '']));
$maxOut = max(1, ...array_values($outcomes ?: [1]));
$cards = [
    ['users', 'g', $newSeekers, 'New job seekers'], ['building', 'y', $newEmployers, 'New employers'],
    ['briefcase', 'g', $jobsPosted, 'Jobs posted'], ['send', 'y', $apps, 'Applications received'],
    ['check', 'g', $accepted, 'Accepted applicants'],
    ['shield', 'y', $verifiedN, 'Employers verified'], ['calendar', 'g', $avgTxt, 'Avg. days to verify an employer'],
    ['flag', 'r', $reportsN, 'Complaints received'],
    ['briefcase', 'y', $activeJobsNow, 'Active jobs right now'], ['eye', 'g', $views, 'Job views (jobs posted in period)'],
];
$outLabel = ['pending' => 'st-pending', 'reviewed' => 'st-reviewed', 'shortlisted' => 'st-shortlisted', 'accepted' => 'st-accepted', 'rejected' => 'st-rejected', 'withdrawn' => 'st-withdrawn'];
$mx = max(1, ...array_map(fn($m) => max($m['jobs'], $m['apps']), array_values($months) ?: [['jobs' => 1, 'apps' => 1]]));

$pageTitle = 'Reports';
require __DIR__ . '/../includes/admin_top.php';
?>
<div class="print-only"><?php xp_letterhead($xp); ?></div>

<div class="ap rp">
  <section class="ap-hero no-print">
    <div>
      <h1>Reports and analytics</h1>
      <p>Pick a period, review the numbers, then print, save as PDF, or open in Excel.</p>
    </div>
    <div class="ap-hero-act">
      <button class="btn btn-gold btn-sm" type="button" onclick="xpOpen(this)" title="Choose what to include, preview it, then print or save"><?= xp_icon('pdf') ?> Generate report</button>
    </div>
  </section>

  <div class="rp-controls no-print">
    <nav class="ap-tabs" aria-label="Report period">
      <?php foreach ($ranges as $k => $l): ?><a class="<?= $range === $k ? 'on' : '' ?>" href="<?= url('admin/reports.php?range=' . $k) ?>" <?= $range === $k ? 'aria-current="page"' : '' ?>><?= e($l) ?></a><?php endforeach; ?>
    </nav>
    <form method="get" class="rp-dates<?= $range === 'custom' ? ' is-on' : '' ?>"><input type="hidden" name="range" value="custom">
      <?= icon('calendar', 18) ?>
      <label>From <input type="date" name="from" value="<?= e($from) ?>" max="<?= $today ?>"></label>
      <label>To <input type="date" name="to" value="<?= e($to) ?>" max="<?= $today ?>"></label>
      <button class="btn btn-primary btn-sm" type="submit">Apply dates</button>
    </form>
  </div>

  <p class="ap-count rp-showing">Showing <b><?= e($periodLabel) ?></b></p>

  <div class="rp-kpis">
    <?php foreach ($cards as [$ic, $tone, $val, $lbl]): ?>
      <div class="rp-kpi t-<?= $tone ?>"><span class="rp-kpi-ico"><?= icon($ic, 20) ?></span><strong><?= is_int($val) ? number_format($val) : e($val) ?></strong><span class="rp-kpi-lbl"><?= e($lbl) ?></span></div>
    <?php endforeach; ?>
  </div>

  <div class="rp-duo">
    <section class="rp-card">
      <header class="rp-card-head"><span class="rp-ico"><?= icon('send', 18) ?></span><h2>Application outcomes</h2></header>
      <?php if (!$outcomes): ?><p class="rp-none">No applications in this period.</p><?php else: ?>
      <div class="rp-rate">
        <div class="rp-ring" style="--p:<?= (int)$rate ?>" role="img" aria-label="Acceptance rate <?= (int)$rate ?> percent"><b><?= (int)$rate ?>%</b></div>
        <p><strong>Acceptance rate</strong><span><?= number_format($accepted) ?> accepted out of <?= number_format($apps) ?> application<?= $apps === 1 ? '' : 's' ?></span></p>
      </div>
      <?php foreach (['pending', 'reviewed', 'shortlisted', 'accepted', 'rejected', 'withdrawn'] as $st): if (empty($outcomes[$st])) continue; ?>
        <div class="rp-bar"><span class="bl"><?= e(app_status($st)[0]) ?></span><span class="bt"><i class="<?= $outLabel[$st] ?>" style="width:<?= round($outcomes[$st] / $maxOut * 100) ?>%"></i></span><b><?= $outcomes[$st] ?></b></div>
      <?php endforeach; endif; ?>
    </section>
    <?php a_table($tables['top'], 'star'); ?>
  </div>

  <section class="rp-card rp-growth">
    <header class="rp-card-head"><span class="rp-ico"><?= icon('chart', 18) ?></span><h2>Growth month by month</h2>
      <span class="rp-legend"><i class="lg-g"></i> Jobs posted <i class="lg-y"></i> Applications</span></header>
    <?php if (!$months): ?><p class="rp-none">No data in this period.</p><?php else: ?>
    <div class="rp-scroll"><div class="rp-months" style="grid-template-columns:repeat(<?= max(1, min(12, count($months))) ?>,minmax(54px,1fr))">
      <?php foreach ($months as $m): ?>
        <div class="rp-mcol"><div class="rp-mbars"><i class="mb-g" style="height:<?= round($m['jobs'] / $mx * 100) ?>%" title="<?= $m['jobs'] ?> jobs"></i><i class="mb-y" style="height:<?= round($m['apps'] / $mx * 100) ?>%" title="<?= $m['apps'] ?> applications"></i></div>
          <span class="rp-mnums"><b class="g"><?= $m['jobs'] ?></b><b class="y"><?= $m['apps'] ?></b></span><span class="rp-mlbl"><?= e($m['label']) ?></span></div>
      <?php endforeach; ?>
    </div></div><?php endif; ?>
  </section>

  <div class="rp-duo">
    <section class="rp-card">
      <header class="rp-card-head"><span class="rp-ico rose"><?= icon('flag', 18) ?></span><h2>Complaints by reason</h2><a class="rp-link no-print" href="<?= url('admin/complaints.php') ?>">Open complaints</a></header>
      <?php if (!$byReason): ?><p class="rp-none">No complaints in this period.</p><?php endif; ?>
      <?php $mr = max(1, ...array_values($byReason ?: [1])); foreach ($byReason as $k => $v): ?>
        <div class="rp-bar"><span class="bl"><?= e($reasonLabel[$k] ?? $k) ?></span><span class="bt"><i class="st-rejected" style="width:<?= round($v / $mr * 100) ?>%"></i></span><b><?= $v ?></b></div>
      <?php endforeach; ?>
      <?php if ($byCStatus): ?><div class="rp-chips"><?php foreach ($byCStatus as $k => $v): ?><span class="rp-chip c-<?= e($k) ?>"><?= e($cStatusLabel[$k] ?? $k) ?> <b><?= $v ?></b></span><?php endforeach; ?></div><?php endif; ?>
    </section>
    <?php a_table($tables['flagemp'], 'flag'); ?>
  </div>

  <div class="rp-duo"><?php a_table($tables['barangay'], 'pin'); a_table($tables['category'], 'tag'); ?></div>
  <div class="rp-duo"><?php a_table($tables['type'], 'briefcase'); a_table($tables['monthly'], 'calendar', false); ?></div>
</div>
<?php xp_script($xp); ?>
<p class="print-only rp-endnote">Generated by the Trabaho Tu Bunawan admin system on <?= e(date('M j, Y g:i A')) ?>. For internal use.</p>
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>