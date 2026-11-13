<?php
require __DIR__ . '/../includes/admin_auth.php';
require_admin();
$pdo = db(); $adminId = (int)$_SESSION['uid'];

$tabs = ['open' => 'Open', 'action_taken' => 'Action taken', 'dismissed' => 'Dismissed'];
$tab = array_key_exists($_GET['tab'] ?? '', $tabs) ? $_GET['tab'] : 'open';
$page = max(1, (int)($_GET['page'] ?? 1)); $per = 10;
$type = ($_GET['type'] ?? '') === 'applicants' ? 'applicants' : 'jobs';   // job complaints (from seekers) or applicant reports (from employers)
$apReady = applicant_reports_ready($pdo);
$typeQs = $type === 'applicants' ? 'type=applicants&' : '';
$reasonLabel = ['scam' => 'Looks like a scam', 'asks_payment' => 'Asks for payment', 'wrong_info' => 'Wrong information', 'discrimination' => 'Discrimination', 'other' => 'Other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = 'admin/complaints.php';
    if (!csrf_check()) { flash('error', 'Your session expired. Please try again.'); redirect($back); }
    $act = $_POST['action'] ?? '';

    // ---------- Applicant reports: dismiss, warn the seeker, or suspend the seeker ----------
    if (str_starts_with($act, 'ap_')) {
        $backAp = 'admin/complaints.php?type=applicants' . ($tab !== 'open' ? '&tab=' . $tab : '');
        $sid = (int)($_POST['seeker_id'] ?? 0);
        $reason = mb_substr(trim($_POST['reason'] ?? ''), 0, 255);
        $s = $pdo->prepare("SELECT id, full_name, status FROM users WHERE id = ? AND role = 'seeker'");
        $s->execute([$sid]);
        $seeker = $s->fetch();
        if (!$apReady) { flash('error', 'Applicant reports need a database update first. See database/migrations/applicant_reports.sql.'); redirect($backAp); }
        if (!$seeker) { flash('error', 'That job seeker no longer exists.'); redirect($backAp); }
        $closeAp = fn(string $st) => $pdo->prepare("UPDATE applicant_reports SET status = ?, handled_by = ? WHERE seeker_id = ? AND status = 'open'")->execute([$st, $adminId, $sid]);

        if ($act === 'ap_dismiss') {
            $pdo->beginTransaction();
            $closeAp('dismissed');
            log_admin('applicant_reports_dismissed', 'user', $sid);
            $pdo->commit();
            flash('success', 'Reports dismissed.');
        } elseif (in_array($act, ['ap_warn', 'ap_suspend'], true)) {
            if (mb_strlen($reason) < 10) { flash('error', 'Write a reason of at least 10 characters.'); redirect($backAp); }
            $pdo->beginTransaction();
            $closeAp('action_taken');
            if ($act === 'ap_warn') {
                warn_user($pdo, $sid, $reason, 'seeker/notifications.php');
            } else {
                $pdo->prepare("UPDATE users SET status = 'suspended' WHERE id = ? AND role = 'seeker'")->execute([$sid]);
                log_admin('seeker_suspended_after_report', 'user', $sid, $reason);
            }
            $pdo->commit();
            flash('success', $act === 'ap_warn' ? $seeker['full_name'] . ' was warned. They were notified.' : $seeker['full_name'] . ' was suspended.');
        }
        redirect($backAp);
    }

    $jobId = (int)($_POST['job_id'] ?? 0);
    $reason = mb_substr(trim($_POST['reason'] ?? ''), 0, 255);
    $s = $pdo->prepare('SELECT id, title, status, red_flags, deadline, employer_id FROM job_posts WHERE id = ?');
    $s->execute([$jobId]);
    $job = $s->fetch();
    if (!$job) { flash('error', 'That job no longer exists.'); redirect($back); }

    $closeReports = fn(string $st) => $pdo->prepare("UPDATE job_reports SET status = ?, handled_by = ? WHERE job_id = ? AND status = 'open'")->execute([$st, $adminId, $jobId]);
    $notify = fn(string $text) => $pdo->prepare('INSERT INTO notifications (user_id, message, link) VALUES (?,?,?)')->execute([$job['employer_id'], $text, 'employer/my-jobs.php']);

    if ($act === 'dismiss') {
        $pdo->beginTransaction();
        $closeReports('dismissed');
        // If the job was hidden only because of complaints, put it back online
        if ($job['status'] === 'pending' && str_contains((string)$job['red_flags'], 'reported')) {
            $left = array_values(array_filter(preg_split('/\s*,\s*/', (string)$job['red_flags']), fn($f) => $f !== '' && $f !== 'reported'));
            $restore = !$left && $job['deadline'] >= date('Y-m-d');
            $pdo->prepare('UPDATE job_posts SET red_flags = ?, status = ? WHERE id = ?')->execute([$left ? implode(', ', $left) : null, $restore ? 'approved' : 'pending', $jobId]);
        }
        log_admin('complaints_dismissed', 'job', $jobId);
        $pdo->commit();
        flash('success', 'Complaints dismissed.');
    } elseif (in_array($act, ['remove', 'warn', 'suspend'], true)) {
        if (mb_strlen($reason) < 10) { flash('error', 'Write a reason of at least 10 characters.'); redirect($back); }
        $pdo->beginTransaction();
        $closeReports('action_taken');
        if ($act === 'remove') {
            $pdo->prepare("UPDATE job_posts SET status = 'removed' WHERE id = ?")->execute([$jobId]);
            $notify('Your job "' . mb_substr($job['title'], 0, 80) . '" was removed after complaints. Reason: ' . $reason);
            log_admin('job_removed_after_complaint', 'job', $jobId, $reason);
        } elseif ($act === 'warn') {
            $notify('Warning about "' . mb_substr($job['title'], 0, 80) . '": ' . $reason);
            log_admin('employer_warned', 'job', $jobId, $reason);
        } else {
            $pdo->prepare("UPDATE users SET status = 'suspended' WHERE id = ? AND role = 'employer'")->execute([$job['employer_id']]);
            $pdo->prepare("UPDATE employer_profiles SET verification_status = 'suspended', rejection_reason = ?, peso_endorsed = 0 WHERE user_id = ?")->execute([$reason, $job['employer_id']]);
            $pdo->prepare("UPDATE job_posts SET status = 'closed' WHERE employer_id = ? AND status IN ('approved','pending')")->execute([$job['employer_id']]);
            log_admin('employer_suspended_after_complaint', 'employer', (int)$job['employer_id'], $reason);
        }
        $pdo->commit();
        flash('success', ['remove' => 'Job removed.', 'warn' => 'Warning sent to the employer.', 'suspend' => 'Employer suspended and all their jobs closed.'][$act]);
    }
    redirect($back . ($tab !== 'open' ? '?tab=' . $tab : ''));
}

$tabCount = [];
foreach ($tabs as $k => $_) { $c = $pdo->prepare('SELECT COUNT(DISTINCT job_id) FROM job_reports WHERE status = ?'); $c->execute([$k]); $tabCount[$k] = (int)$c->fetchColumn(); }
$apCount = array_fill_keys(array_keys($tabs), 0);
if ($apReady) foreach ($tabs as $k => $_) { $c = $pdo->prepare('SELECT COUNT(DISTINCT seeker_id) FROM applicant_reports WHERE status = ?'); $c->execute([$k]); $apCount[$k] = (int)$c->fetchColumn(); }
$total = $type === 'applicants' ? $apCount[$tab] : $tabCount[$tab];
$groups = []; $details = []; $priorWarn = [];
if ($type === 'applicants') {
    if ($apReady && $total > 0) {
        $r = $pdo->prepare('SELECT u.id, u.full_name, u.email, u.status, COUNT(r.id) AS cnt, COUNT(DISTINCT r.employer_id) AS emps, MAX(r.created_at) AS latest
            FROM applicant_reports r JOIN users u ON u.id = r.seeker_id
            WHERE r.status = ? GROUP BY u.id, u.full_name, u.email, u.status ORDER BY cnt DESC, latest DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
        $r->execute([$tab]);
        $groups = $r->fetchAll();
    }
    if ($groups) {
        $ids = implode(',', array_map('intval', array_column($groups, 'id')));
        $d = $pdo->prepare("SELECT r.seeker_id, r.reason, r.details, r.created_at, ep.employer_name, j.title
            FROM applicant_reports r LEFT JOIN employer_profiles ep ON ep.user_id = r.employer_id LEFT JOIN job_posts j ON j.id = r.job_id
            WHERE r.status = ? AND r.seeker_id IN ($ids) ORDER BY r.created_at DESC");
        $d->execute([$tab]);
        foreach ($d as $x) $details[$x['seeker_id']][] = $x;
        foreach ($pdo->query("SELECT target_id, COUNT(*) AS c FROM admin_activity_logs WHERE action = 'user_warned' AND target_type = 'user' AND target_id IN ($ids) GROUP BY target_id") as $w) $priorWarn[(int)$w['target_id']] = (int)$w['c'];
    }
}
if ($type === 'jobs') {
$r = $pdo->prepare("SELECT j.id, j.title, j.status, ep.employer_name, ep.user_id AS emp_id, COUNT(r.id) AS cnt, MAX(r.created_at) AS latest
    FROM job_reports r JOIN job_posts j ON j.id = r.job_id JOIN employer_profiles ep ON ep.user_id = j.employer_id
    WHERE r.status = ? GROUP BY j.id, j.title, j.status, ep.employer_name, ep.user_id ORDER BY cnt DESC, latest DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$r->execute([$tab]);
$groups = $r->fetchAll();
$details = [];
if ($groups) {
    $ids = implode(',', array_map('intval', array_column($groups, 'id')));
    $d = $pdo->prepare("SELECT r.job_id, r.reason, r.details, r.created_at, u.full_name FROM job_reports r LEFT JOIN users u ON u.id = r.reporter_id
        WHERE r.status = ? AND r.job_id IN ($ids) ORDER BY r.created_at DESC");
    $d->execute([$tab]);
    foreach ($d as $x) $details[$x['job_id']][] = $x;
}
}

$tabDot = ['open' => '#F2B705', 'action_taken' => '#2FB463', 'dismissed' => '#9AA9A0'];
$reasonCls = ['scam' => 'scam', 'asks_payment' => 'pay', 'wrong_info' => 'info', 'discrimination' => 'disc', 'other' => 'other'];
$emptyMsg = ['open' => 'There are no open complaints right now. New ones will show up here.', 'action_taken' => 'No complaint has been acted on yet.', 'dismissed' => 'No complaint has been dismissed yet.'];

// ---------- Export: Print, Save as PDF, Save as CSV (every complaint in the current tab) ----------
require_once __DIR__ . '/../includes/admin_export.php';
if ($type === 'jobs') {
$xr = $pdo->prepare('SELECT j.title, ep.employer_name, r.reason, r.details, r.created_at, u.full_name
    FROM job_reports r JOIN job_posts j ON j.id = r.job_id JOIN employer_profiles ep ON ep.user_id = j.employer_id
    LEFT JOIN users u ON u.id = r.reporter_id WHERE r.status = ? ORDER BY r.created_at DESC LIMIT ' . XP_MAX_ROWS);
$xr->execute([$tab]);
$xpRows = []; $xn = 0;
foreach ($xr as $x) $xpRows[] = [++$xn, date('M j, Y g:i A', strtotime($x['created_at'])), $x['title'], $x['employer_name'],
    $reasonLabel[$x['reason']] ?? $x['reason'], trim((string)$x['details']) !== '' ? trim((string)$x['details']) : 'No details provided', $x['full_name'] ?? 'Guest'];
$xp = ['title' => 'Complaints Report', 'slug' => 'complaints',
    'purpose' => 'Complaints filed by job seekers against job posts, with the reason given and who filed them.',
    'filters' => ['Status' => $tabs[$tab]],
    'head' => ['No.', 'Date filed', 'Job title', 'Employer', 'Reason', 'Details', 'Filed by'],
    'rows' => $xpRows, 'landscape' => true];
}

$pageTitle = 'Complaints';
require __DIR__ . '/../includes/admin_top.php';
?>
<link rel="stylesheet" href="<?= e(url('assets/css/admin-complaints.css')) ?>">
<div class="cp-page">
<div class="cp-hero">
  <div><h1>Complaints</h1><p><?php if ($type === 'applicants'): ?>Reports from employers about job seekers, grouped by seeker. Dismiss them, warn the seeker, or suspend the seeker.<?php else: ?>Complaints filed by job seekers, grouped by job. Dismiss them, warn the employer, remove the job, or suspend the employer.<?php endif; ?></p></div>
  <?php if ($tabCount['open'] + $apCount['open'] > 0): ?>
    <span style="display:flex;gap:8px;flex-wrap:wrap">
      <?php if ($tabCount['open'] > 0): ?><span class="cp-chip cp-chip-gold"><?= icon('flag', 16) ?><?= number_format($tabCount['open']) ?> <?= $tabCount['open'] === 1 ? 'job needs' : 'jobs need' ?> review</span><?php endif; ?>
      <?php if ($apCount['open'] > 0): ?><span class="cp-chip cp-chip-gold"><?= icon('flag', 16) ?><?= number_format($apCount['open']) ?> <?= $apCount['open'] === 1 ? 'applicant needs' : 'applicants need' ?> review</span><?php endif; ?>
    </span>
  <?php else: ?><span class="cp-chip cp-chip-glass"><?= icon('check', 16) ?>All caught up</span><?php endif; ?>
</div>

<nav class="cp-types" aria-label="Complaint type">
  <a class="<?= $type === 'jobs' ? 'on' : '' ?>" href="<?= url('admin/complaints.php') ?>"<?= $type === 'jobs' ? ' aria-current="page"' : '' ?>>Job complaints<span class="cp-badge"><?= $tabCount['open'] ?></span></a>
  <a class="<?= $type === 'applicants' ? 'on' : '' ?>" href="<?= url('admin/complaints.php?type=applicants') ?>"<?= $type === 'applicants' ? ' aria-current="page"' : '' ?>>Applicant reports<span class="cp-badge"><?= $apCount['open'] ?></span></a>
</nav>

<?php if ($type === 'applicants' && !$apReady): ?>
  <div class="alert alert-info" role="status">Applicant reports need a one-time database update. Run <b>database/migrations/applicant_reports.sql</b> in the Supabase SQL editor, then reload this page.</div>
<?php endif; ?>


<nav class="cp-tabs" aria-label="Filter complaints"><?php foreach ($tabs as $k => $l): ?><a class="<?= $tab === $k ? 'on' : '' ?>" href="<?= url('admin/complaints.php?' . $typeQs . 'tab=' . $k) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><i class="cp-dot" style="--c:<?= $tabDot[$k] ?>"></i><?= e($l) ?><span class="cp-badge"><?= $type === 'applicants' ? $apCount[$k] : $tabCount[$k] ?></span></a><?php endforeach; ?></nav>

<?php if (!$groups): ?><div class="cp-empty"><div class="cp-empty-ico"><?= icon('check', 26) ?></div><h3><?= $type === 'applicants' ? 'No reports here' : 'No complaints here' ?></h3><p><?= e($type === 'applicants' ? str_replace(['complaints', 'complaint'], ['reports', 'report'], $emptyMsg[$tab]) : $emptyMsg[$tab]) ?></p></div><?php endif; ?>

<?php if ($type === 'applicants'): foreach ($groups as $g): $list = $details[$g['id']] ?? []; $was = $priorWarn[(int)$g['id']] ?? 0; $isSusp = $g['status'] === 'suspended'; ?>
  <article class="cp-card<?= $g['cnt'] >= 3 ? ' cp-hot' : '' ?>">
    <header class="cp-head">
      <span class="cp-tile" aria-hidden="true"><?= icon('user', 20) ?></span>
      <div class="cp-title"><h2><?= e($g['full_name']) ?></h2>
        <p class="cp-meta"><span><?= icon('mail', 14) ?><?= e($g['email']) ?></span><span><b><?= (int)$g['cnt'] ?></b> report<?= $g['cnt'] > 1 ? 's' : '' ?> from <b><?= (int)$g['emps'] ?></b> employer<?= $g['emps'] > 1 ? 's' : '' ?></span><span><?= icon('clock', 14) ?>Latest <?= e(date('M j, g:i A', strtotime($g['latest']))) ?></span></p></div>
      <div class="cp-head-r">
        <?php if ($g['cnt'] >= 3): ?><span class="cp-hotpill">Needs attention</span><?php endif; ?>
        <?php if ($was > 0): ?><span class="st st-pending" title="Warnings already sent to this seeker">Warned x<?= $was ?></span><?php endif; ?>
        <span class="st cp-st <?= $isSusp ? 'st-bad' : 'st-ok' ?>">Account: <?= $isSusp ? 'Suspended' : 'Active' ?></span>
        <a class="btn btn-outline btn-sm" href="<?= url('admin/users.php?q=' . urlencode($g['email'])) ?>">Open user <?= icon('arrow', 14) ?></a>
      </div>
    </header>
    <ul class="cp-list">
      <?php foreach (array_slice($list, 0, 8) as $x): $who = $x['employer_name'] ?? 'Employer'; ?>
        <li class="cp-item">
          <span class="cp-reason cp-r-<?= e(['fake_identity' => 'scam', 'asks_money' => 'pay', 'harassment' => 'disc', 'spam' => 'info'][$x['reason']] ?? 'other') ?>"><?= e(APPLICANT_REPORT_REASONS[$x['reason']] ?? $x['reason']) ?></span>
          <?php if (trim((string)($x['details'] ?? '')) !== ''): ?><p class="cp-text"><?= e($x['details']) ?></p><?php else: ?><p class="cp-text none">No details provided.</p><?php endif; ?>
          <div class="cp-by"><span class="cp-av" aria-hidden="true"><?= e(initials($who)) ?></span>
            <div><b><?= e($who) ?></b><small><?= $x['title'] ? 'For ' . e($x['title']) . ' &middot; ' : '' ?><?= e(date('M j, g:i A', strtotime($x['created_at']))) ?></small></div></div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if (count($list) > 8): ?><p class="cp-more">Showing the latest 8 of <?= count($list) ?>.</p><?php endif; ?>
    <?php if ($tab === 'open'): ?>
      <form method="post" class="form cp-actions"><?= csrf_field() ?><input type="hidden" name="seeker_id" value="<?= (int)$g['id'] ?>">
        <label for="ars<?= (int)$g['id'] ?>">Reason <span>(required to warn or suspend; the seeker sees it, so do not name the employer)</span></label>
        <textarea id="ars<?= (int)$g['id'] ?>" name="reason" rows="2" maxlength="220"></textarea>
        <div class="cp-btns">
          <button class="btn btn-outline" name="action" value="ap_dismiss" data-confirm="All open reports about this seeker will be dismissed." data-confirm-title="Dismiss reports?" data-confirm-ok="Dismiss" data-confirm-tone="info">Dismiss</button>
          <span class="cp-spacer"></span>
          <button class="btn btn-gold" name="action" value="ap_warn">Warn seeker</button>
          <?php if (!$isSusp): ?><button class="btn cp-btn-strong" name="action" value="ap_suspend" data-confirm="The seeker will be suspended and locked out." data-confirm-title="Suspend seeker?" data-confirm-ok="Suspend" data-confirm-tone="danger">Suspend seeker</button><?php endif; ?>
        </div>
      </form>
    <?php endif; ?>
  </article>
<?php endforeach; else: foreach ($groups as $g): [$jl, $jc] = job_status($g['status']); $list = $details[$g['id']] ?? []; ?>
  <article class="cp-card<?= $g['cnt'] >= 3 ? ' cp-hot' : '' ?>">
    <header class="cp-head">
      <span class="cp-tile" aria-hidden="true"><?= icon('flag', 20) ?></span>
      <div class="cp-title"><h2><?= e($g['title']) ?></h2>
        <p class="cp-meta"><span><?= icon('building', 14) ?><?= e($g['employer_name']) ?></span><span><b><?= (int)$g['cnt'] ?></b> complaint<?= $g['cnt'] > 1 ? 's' : '' ?></span><span><?= icon('clock', 14) ?>Latest <?= e(date('M j, g:i A', strtotime($g['latest']))) ?></span></p></div>
      <div class="cp-head-r">
        <?php if ($g['cnt'] >= 3): ?><span class="cp-hotpill">Needs attention</span><?php endif; ?>
        <span class="st cp-st <?= e($jc) ?>">Job: <?= e($jl) ?></span>
        <a class="btn btn-outline btn-sm" href="<?= url('admin/job-review.php?id=' . (int)$g['id']) ?>">Open job <?= icon('arrow', 14) ?></a>
      </div>
    </header>
    <ul class="cp-list">
      <?php foreach (array_slice($list, 0, 8) as $x): $who = $x['full_name'] ?? 'Guest'; ?>
        <li class="cp-item">
          <span class="cp-reason cp-r-<?= $reasonCls[$x['reason']] ?? 'other' ?>"><?= e($reasonLabel[$x['reason']] ?? $x['reason']) ?></span>
          <?php if (trim((string)($x['details'] ?? '')) !== ''): ?><p class="cp-text"><?= e($x['details']) ?></p><?php else: ?><p class="cp-text none">No details provided.</p><?php endif; ?>
          <div class="cp-by"><span class="cp-av<?= $x['full_name'] === null ? ' guest' : '' ?>" aria-hidden="true"><?= $x['full_name'] === null ? '?' : e(initials($who)) ?></span>
            <div><b><?= e($who) ?></b><small><?= e(date('M j, g:i A', strtotime($x['created_at']))) ?></small></div></div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if (count($list) > 8): ?><p class="cp-more">Showing the latest 8 of <?= count($list) ?>.</p><?php endif; ?>
    <?php if ($tab === 'open'): ?>
      <form method="post" class="form cp-actions"><?= csrf_field() ?><input type="hidden" name="job_id" value="<?= (int)$g['id'] ?>">
        <label for="rs<?= (int)$g['id'] ?>">Reason <span>(required to warn, remove, or suspend; the employer sees it)</span></label>
        <textarea id="rs<?= (int)$g['id'] ?>" name="reason" rows="2" maxlength="255"></textarea>
        <div class="cp-btns">
          <button class="btn btn-outline" name="action" value="dismiss" data-confirm="All open complaints for this job will be dismissed." data-confirm-title="Dismiss complaints?" data-confirm-ok="Dismiss" data-confirm-tone="info">Dismiss</button>
          <span class="cp-spacer"></span>
          <button class="btn btn-gold" name="action" value="warn">Warn employer</button>
          <button class="btn btn-danger" name="action" value="remove" data-confirm="This job will be removed." data-confirm-title="Remove job?" data-confirm-ok="Remove job" data-confirm-tone="danger">Remove job</button>
          <button class="btn cp-btn-strong" name="action" value="suspend" data-confirm="The employer will be suspended and all their jobs closed." data-confirm-title="Suspend employer?" data-confirm-ok="Suspend" data-confirm-tone="danger">Suspend employer</button>
        </div>
      </form>
    <?php endif; ?>
  </article>
<?php endforeach; endif; ?>
<?= pager($total, $page, $per, url('admin/complaints.php?' . $typeQs . 'tab=' . $tab)) ?>
</div>
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>
