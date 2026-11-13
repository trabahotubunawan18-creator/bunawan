<?php
require __DIR__ . '/../includes/admin_auth.php';
require __DIR__ . '/../includes/redflags.php';
require_admin();
$pdo = db(); $adminId = (int)$_SESSION['uid'];
$id = (int)($_GET['id'] ?? 0);
$from = ($_GET['from'] ?? '') === 'flagged' ? 'flagged' : 'jobs';

$s = $pdo->prepare('SELECT j.*, ep.employer_name, ep.logo_path, ep.verification_status, ep.approved_post_count, b.name AS barangay, c.name AS category
    FROM job_posts j JOIN employer_profiles ep ON ep.user_id = j.employer_id JOIN barangays b ON b.id = j.barangay_id
    JOIN job_categories c ON c.id = j.category_id WHERE j.id = ?');
$s->execute([$id]);
$j = $s->fetch();
if (!$j) { http_response_code(404); exit('Job not found.'); }
$status = $j['status'];
$allowed = ['pending' => ['approve', 'reject', 'remove'], 'rejected' => ['approve', 'remove'], 'approved' => ['close', 'reject', 'remove'],
            'closed' => ['remove'], 'expired' => ['remove'], 'removed' => []][$status] ?? [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? ''; $reason = mb_substr(trim($_POST['reason'] ?? ''), 0, 255);
    if (!csrf_check()) $errors[] = 'Your session expired. Please try again.';
    elseif (!in_array($act, $allowed, true)) $errors[] = 'That action is not available for this job.';
    elseif ($act !== 'approve' && mb_strlen($reason) < 10) $errors[] = 'Write a reason of at least 10 characters. The employer will see it.';
    else {
        $notify = fn(string $t) => $pdo->prepare('INSERT INTO notifications (user_id, message, link) VALUES (?,?,?)')->execute([$j['employer_id'], $t, 'employer/my-jobs.php']);
        $pdo->beginTransaction();
        if ($act === 'approve') {
            $pdo->prepare("UPDATE job_posts SET status = 'approved', approved_at = NOW(), rejection_reason = NULL WHERE id = ?")->execute([$id]);
            if ($status === 'pending') $pdo->prepare('UPDATE employer_profiles SET approved_post_count = approved_post_count + 1 WHERE user_id = ?')->execute([$j['employer_id']]);
            $pdo->prepare("UPDATE job_reports SET status = 'dismissed', handled_by = ? WHERE job_id = ? AND status = 'open'")->execute([$adminId, $id]);
            $notify('Your job "' . $j['title'] . '" was approved and is now live.');
            log_admin('job_approved', 'job', $id, $j['title']);
            $msg = 'Job approved and published.';
        } elseif ($act === 'reject') {
            $pdo->prepare("UPDATE job_posts SET status = 'rejected', rejection_reason = ? WHERE id = ?")->execute([$reason, $id]);
            $pdo->prepare("UPDATE job_reports SET status = 'action_taken', handled_by = ? WHERE job_id = ? AND status = 'open'")->execute([$adminId, $id]);
            $notify('Your job "' . $j['title'] . '" was not approved. Open My job posts to see why.');
            log_admin('job_rejected', 'job', $id, $reason);
            $msg = 'Job rejected. The employer was told why.';
        } elseif ($act === 'close') {
            $pdo->prepare("UPDATE job_posts SET status = 'closed' WHERE id = ?")->execute([$id]);
            $notify('Your job "' . $j['title'] . '" was closed by our team: ' . $reason);
            log_admin('job_closed', 'job', $id, $reason);
            $msg = 'Job closed.';
        } else {
            $pdo->prepare("UPDATE job_posts SET status = 'removed', rejection_reason = ? WHERE id = ?")->execute([$reason, $id]);
            $pdo->prepare("UPDATE job_reports SET status = 'action_taken', handled_by = ? WHERE job_id = ? AND status = 'open'")->execute([$adminId, $id]);
            $notify('Your job "' . $j['title'] . '" was removed by our team: ' . $reason);
            log_admin('job_removed', 'job', $id, $reason);
            $msg = 'Job removed from the site.';
        }
        $pdo->commit();
        flash('success', $msg);
        redirect($from === 'flagged' ? 'admin/flagged.php' : 'admin/jobs.php');
    }
}

$data = ['title' => $j['title'], 'description' => $j['description'], 'responsibilities' => $j['responsibilities'], 'qualifications' => $j['qualifications'],
         'work_address' => $j['work_address'], 'salary_min' => $j['salary_min'], 'salary_max' => $j['salary_max'], 'salary_period' => $j['salary_period']];
$flags = redflag_check($data);
$stored = flag_codes_to_text($j['red_flags']);
$rep = $pdo->prepare('SELECT jr.reason, jr.details, jr.status, jr.created_at, u.full_name FROM job_reports jr LEFT JOIN users u ON u.id = jr.reporter_id WHERE jr.job_id = ? ORDER BY jr.created_at DESC');
$rep->execute([$id]);
$reports = $rep->fetchAll();
$reasonLabel = ['scam' => 'Looks like a scam', 'asks_payment' => 'Asks for payment', 'wrong_info' => 'Wrong information', 'discrimination' => 'Discrimination', 'other' => 'Other'];
[$jl, $jc] = job_status($status === 'approved' && $j['deadline'] < date('Y-m-d') ? 'expired' : $status);
$can = fn($a) => in_array($a, $allowed, true);

$pageTitle = 'Review job';
require __DIR__ . '/../includes/admin_top.php';
?>
<?php
// Split a multi-line field into clean lines (drops empty lines and leading "-" / "*" / bullet marks)
$rvLines = function ($t): array {
    $out = [];
    foreach (preg_split('/\R/u', (string)$t) as $l) { $l = trim(preg_replace('/^[\-\*\x{2022}]\s*/u', '', trim($l))); if ($l !== '') $out[] = $l; }
    return $out;
};
$rvBlock = function (string $title, $text) use ($rvLines) {
    $lines = $rvLines($text);
    if (!$lines) return;
    echo '<div class="rv-sec"><h3>' . e($title) . '</h3>';
    if (count($lines) > 1) { echo '<ul class="rv-list">'; foreach ($lines as $l) echo '<li>' . e($l) . '</li>'; echo '</ul>'; }
    else { echo '<p>' . e($lines[0]) . '</p>'; }
    echo '</div>';
};
?>
<div class="rv-page">
<a class="rv-back" href="<?= url($from === 'flagged' ? 'admin/flagged.php' : 'admin/jobs.php') ?>">&larr; Back</a>

<header class="ad-hero rv-hero">
  <div class="ad-hero-main"><h1 class="dash-title"><?= e($j['title']) ?></h1>
    <p class="dash-sub"><a href="<?= url('admin/verify-review.php?id=' . (int)$j['employer_id']) ?>"><span class="av av-sq" style="display:inline-flex;width:24px;height:24px;border-radius:7px;font-size:10px;vertical-align:middle;margin-right:6px;border:1px solid var(--line)" aria-hidden="true"><?= avatar_inner($j['logo_path'] ?? null, $j['employer_name']) ?></span><?= e($j['employer_name']) ?></a> &middot; <?= icon('pin', 13) ?> <?= e($j['barangay']) ?> &middot; <?= e($j['category']) ?></p></div>
  <div class="ad-chips"><span class="st rv-st <?= e($jc) ?>"><?= e($jl) ?></span></div>
</header>
<?php if ($errors): ?><div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="rv-grid">
  <div class="rv-main">
    <?php if ($flags || $stored): ?>
    <section class="rv-card rv-flag">
      <h2 class="rv-h"><?= icon('flag', 17) ?> Why it was flagged</h2>
      <?php foreach ($flags + array_map(fn($m) => ['msg' => $m, 'matches' => []], array_diff_key($stored, $flags)) as $c => $f): ?>
        <div class="rv-flag-item"><b><?= e($f['msg']) ?></b><?php if ($f['matches']): ?><small>Found: <?= e(implode(', ', array_map(fn($m) => '"' . $m . '"', $f['matches']))) ?></small><?php endif; ?></div>
      <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <section class="rv-card">
      <div class="rv-facts">
        <div class="rv-fact rv-fact--gold"><span class="rv-fact-ico"><?= icon('tag', 16) ?></span><div><small>Salary</small><b><?= e(salary_text($j)) ?></b></div></div>
        <div class="rv-fact"><span class="rv-fact-ico"><?= icon('briefcase', 16) ?></span><div><small>Type</small><b><?= e(type_label($j['employment_type'])) ?></b></div></div>
        <div class="rv-fact"><span class="rv-fact-ico"><?= icon('users', 16) ?></span><div><small>Slots</small><b><?= (int)$j['slots'] ?></b></div></div>
        <div class="rv-fact"><span class="rv-fact-ico"><?= icon('calendar', 16) ?></span><div><small>Deadline</small><b><?= e(date('M j, Y', strtotime($j['deadline']))) ?></b></div></div>
        <div class="rv-fact"><span class="rv-fact-ico"><?= icon('file', 16) ?></span><div><small>Resume</small><b><?= e(ucfirst($j['resume_requirement'])) ?></b></div></div>
        <div class="rv-fact"><span class="rv-fact-ico"><?= icon('check', 16) ?></span><div><small>Employer's approved posts</small><b><?= (int)$j['approved_post_count'] ?></b></div></div>
      </div>

      <div class="rv-sec"><h3>Description</h3><p><?= nl2br(e($j['description'])) ?></p></div>
      <?php $rvBlock('Responsibilities', $j['responsibilities']); $rvBlock('Qualifications', $j['qualifications']); ?>
      <?php if ($j['work_address']): ?><div class="rv-sec"><h3>Work address</h3><p><?= e($j['work_address']) ?></p>
        <?php if ($j['latitude'] !== null): ?><a class="rv-map" href="https://www.google.com/maps/search/?api=1&query=<?= e($j['latitude'] . ',' . $j['longitude']) ?>" target="_blank" rel="noopener noreferrer"><?= icon('pin', 14) ?> See pin on map</a><?php endif; ?></div>
      <?php elseif ($j['latitude'] !== null): ?><div class="rv-sec"><a class="rv-map" href="https://www.google.com/maps/search/?api=1&query=<?= e($j['latitude'] . ',' . $j['longitude']) ?>" target="_blank" rel="noopener noreferrer"><?= icon('pin', 14) ?> See pin on map</a></div><?php endif; ?>
      <?php if ($j['rejection_reason']): ?><div class="rv-note"><b>Last reason given</b><p><?= e($j['rejection_reason']) ?></p></div><?php endif; ?>
    </section>

    <?php if ($reports): ?>
    <section class="rv-card">
      <h2 class="rv-h">Reports <span class="rv-badge"><?= count($reports) ?></span></h2>
      <?php foreach ($reports as $r): ?>
        <div class="rv-rep"><div><b><?= e($reasonLabel[$r['reason']] ?? $r['reason']) ?></b><?= $r['details'] ? '<p>' . e($r['details']) . '</p>' : '' ?>
          <small><?= e($r['full_name'] ?? 'Anonymous visitor') ?> &middot; <?= e(date('M j, g:i A', strtotime($r['created_at']))) ?></small></div>
          <span class="st st-off"><?= e(ucfirst(str_replace('_', ' ', $r['status']))) ?></span></div>
      <?php endforeach; ?>
    </section>
    <?php endif; ?>
  </div>

  <form method="post" class="rv-card rv-decision form decision" novalidate>
    <?= csrf_field() ?>
    <h2 class="rv-h">Decision</h2>
    <?php if (!$allowed): ?><p class="rv-hint">This job was removed. No actions are available.</p><?php else: ?>
      <label for="jr-reason">Reason <span>(required to reject, close, or remove)</span></label>
      <textarea id="jr-reason" name="reason" rows="4" maxlength="255" placeholder="The employer will see this message."><?= e($_POST['reason'] ?? '') ?></textarea>
      <div class="dec-btns">
        <?php if ($can('approve')): ?><button class="btn btn-primary" name="action" value="approve" type="submit"><?= $status === 'rejected' ? 'Approve after all' : 'Approve and publish' ?></button><?php endif; ?>
        <?php if ($can('reject')): ?><button class="btn btn-gold" name="action" value="reject" type="submit"><?= $status === 'approved' ? 'Take down (reject)' : 'Reject' ?></button><?php endif; ?>
        <?php if ($can('close')): ?><button class="btn btn-outline" name="action" value="close" type="submit">Close this job</button><?php endif; ?>
      </div>
      <?php if ($can('remove')): ?><div class="dec-danger"><button class="btn btn-danger" name="action" value="remove" type="submit" data-confirm="This job will be removed from the site." data-confirm-title="Remove job?" data-confirm-ok="Remove" data-confirm-tone="danger">Remove</button></div><?php endif; ?>
    <?php endif; ?>
    <?php if ($status === 'approved' && $j['deadline'] >= date('Y-m-d')): ?><a class="rv-viewlink" href="<?= url('job.php?id=' . $id) ?>" target="_blank"><?= icon('eye', 15) ?> View the public page</a><?php endif; ?>
  </form>
</div>
</div><!-- /.rv-page -->
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>
