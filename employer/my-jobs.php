<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/redflags.php';
require_role('employer');
$pdo = db(); $uid = (int)$_SESSION['uid'];
$pe = $pdo->prepare('SELECT verification_status FROM employer_profiles WHERE user_id = ?');
$pe->execute([$uid]);
[, , $canPost] = verif_info((string)$pe->fetchColumn());

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $act = $_POST['action'] ?? ''; $jid = (int)($_POST['job_id'] ?? 0);
    $g = $pdo->prepare("SELECT * FROM job_posts WHERE id = ? AND employer_id = ? AND status <> 'removed'");   // ownership check
    $g->execute([$jid, $uid]);
    $j = $g->fetch();
    if (!$j) { flash('error', 'That job was not found.'); redirect('employer/my-jobs.php'); }

    if ($act === 'close' && in_array($j['status'], ['approved', 'pending'], true)) {
        $pdo->prepare("UPDATE job_posts SET status = 'closed' WHERE id = ? AND employer_id = ?")->execute([$jid, $uid]);
        flash('success', 'Job closed. It no longer accepts applications.');
    } elseif ($act === 'reopen' && $j['status'] === 'closed') {
        $lim = employer_post_limits($pdo, $uid);
        if (!$canPost) flash('error', 'Your account must be verified to reopen a job.');
        elseif ($j['deadline'] < date('Y-m-d')) flash('error', 'The deadline has passed. Use Renew to set a new deadline.');
        elseif ($lim['strikes'] >= FLAG_STRIKES) flash('error', 'Posting is paused until our team reviews your account.');
        elseif ($lim['active'] >= $lim['limit']) flash('error', 'You reached your limit of ' . $lim['limit'] . ' active posts. Close another job first.');
        else {
            $hold = !empty($j['red_flags']) || setting('post_mode', 'auto') === 'manual';
            $pdo->prepare('UPDATE job_posts SET status = ?, approved_at = CASE WHEN CAST(? AS text) = \'approved\' THEN COALESCE(approved_at, NOW()) ELSE NULL END WHERE id = ? AND employer_id = ?')
                ->execute([$hold ? 'pending' : 'approved', $hold ? 'pending' : 'approved', $jid, $uid]);
            if ($hold) { notify_admins($pdo, 'Flagged post to review: ' . $j['title'], 'admin/job-review.php?id=' . $jid . '&from=flagged'); flash('info', 'Reopened, but it is held for a quick admin review first.'); }
            else flash('success', 'Job reopened and live again.');
        }
    } elseif ($act === 'delete') {
        $c = $pdo->prepare('SELECT COUNT(*) FROM job_applications WHERE job_id = ?');
        $c->execute([$jid]);
        if ((int)$c->fetchColumn() > 0) flash('error', 'This job has applicants, so it cannot be deleted. Close it instead.');
        else { $pdo->prepare('DELETE FROM job_posts WHERE id = ? AND employer_id = ?')->execute([$jid, $uid]); flash('success', 'Job deleted.'); }
    }
    redirect('employer/my-jobs.php' . (!empty($_GET['tab']) ? '?tab=' . urlencode($_GET['tab']) : ''));
}

$tabs = ['active' => 'Active', 'pending' => 'Pending review', 'closed' => 'Closed', 'expired' => 'Expired', 'rejected' => 'Rejected', 'all' => 'All'];
$tab = array_key_exists($_GET['tab'] ?? '', $tabs) ? $_GET['tab'] : 'active';
$cond = ['active' => "status = 'approved' AND deadline >= CURRENT_DATE", 'pending' => "status = 'pending'", 'closed' => "status = 'closed'",
         'expired' => "((status = 'approved' AND deadline < CURRENT_DATE) OR status = 'expired')", 'rejected' => "status = 'rejected'", 'all' => '1=1'];
$cr = $pdo->prepare("SELECT SUM(CASE WHEN " . $cond['active'] . " THEN 1 ELSE 0 END) a, SUM(CASE WHEN " . $cond['pending'] . " THEN 1 ELSE 0 END) p, SUM(CASE WHEN " . $cond['closed'] . " THEN 1 ELSE 0 END) c, SUM(CASE WHEN " . $cond['expired'] . " THEN 1 ELSE 0 END) x, SUM(CASE WHEN " . $cond['rejected'] . " THEN 1 ELSE 0 END) r, COUNT(*) t
    FROM job_posts WHERE employer_id = ? AND status <> 'removed'");
$cr->execute([$uid]);
$cc = $cr->fetch();
$counts = ['active' => (int)$cc['a'], 'pending' => (int)$cc['p'], 'closed' => (int)$cc['c'], 'expired' => (int)$cc['x'], 'rejected' => (int)$cc['r'], 'all' => (int)$cc['t']];

$q = $pdo->prepare("SELECT j.*, b.name AS barangay, (SELECT COUNT(*) FROM job_applications a WHERE a.job_id = j.id) AS apps
    FROM job_posts j JOIN barangays b ON b.id = j.barangay_id WHERE j.employer_id = ? AND j.status <> 'removed' AND " . $cond[$tab] . " ORDER BY j.created_at DESC");
$q->execute([$uid]);
$jobs = $q->fetchAll();

$pageTitle = 'My job posts';
require __DIR__ . '/../includes/emp_top.php';
?>
<header class="ep-head">
  <div><h1 class="dash-title ep-title">My job posts</h1><p class="ep-sub">Manage, edit, and close your vacancies.</p></div>
  <div class="ep-head-end">
    <span class="ep-count"><b><?= (int)$counts['all'] ?></b> <?= (int)$counts['all'] === 1 ? 'post' : 'posts' ?></span>
    <?php if ($canPost): ?><a class="btn btn-gold" href="<?= url('employer/post-job.php') ?>"><?= icon('plus', 16) ?> Post a Job</a><?php endif; ?>
  </div>
</header>

<div class="filter-tabs">
  <?php foreach ($tabs as $k => $l): ?><a class="<?= $tab === $k ? 'on' : '' ?>" href="<?= url('employer/my-jobs.php?tab=' . $k) ?>"><?= e($l) ?> (<?= $counts[$k] ?>)</a><?php endforeach; ?>
</div>

<?php if (!$jobs): ?>
  <div class="card empty"><img src="<?= url('assets/img/empty-no-jobs.svg') ?>" alt="" width="130" onerror="this.style.display='none'">
    <h3>No jobs here</h3><p><?= $canPost ? 'Post a vacancy and local job seekers will see it.' : 'You can post jobs once your account is verified.' ?></p>
    <?php if ($canPost): ?><a class="btn btn-primary" href="<?= url('employer/post-job.php') ?>">Post a Job</a><?php endif; ?></div>
<?php else: foreach ($jobs as $j):
  $isExp = $j['status'] === 'expired' || ($j['status'] === 'approved' && $j['deadline'] < date('Y-m-d'));
  [$jl, $jc] = job_status($isExp ? 'expired' : $j['status']);
  $reasons = flag_codes_to_text($j['red_flags']); ?>
  <div class="card panel-card job-row">
    <div class="jr-top">
      <div style="flex:1;min-width:220px">
        <h3 style="margin:0 0 2px;font-size:17px"><?= e($j['title']) ?></h3>
        <div class="small"><?= e($j['barangay']) ?> &middot; <?= e(type_label($j['employment_type'])) ?> &middot; <?= e(salary_text($j)) ?></div>
      </div>
      <span class="st <?= e($jc) ?>"><?= e($jl) ?></span>
    </div>
    <div class="jr-stats"><span><?= icon('calendar', 14) ?> Deadline <?= e(date('M j, Y', strtotime($j['deadline']))) ?></span><span><?= icon('eye', 14) ?> <?= (int)$j['views'] ?> views</span><span><?= icon('users', 14) ?> <?= (int)$j['apps'] ?> applicants</span><span>Posted <?= e(date('M j', strtotime($j['created_at']))) ?></span></div>
    <?php if ($j['status'] === 'pending'): ?>
      <div class="notice"><b>Held for review.</b> <?= $reasons ? e(implode(' ', $reasons)) : 'An admin will check this post soon.' ?> You can edit the post to fix it.</div>
    <?php elseif ($j['status'] === 'rejected' && $j['rejection_reason']): ?>
      <div class="alert alert-error" style="margin:0"><b>Not approved:</b> <?= e($j['rejection_reason']) ?></div>
    <?php endif; ?>
    <div class="jr-actions">
      <?php if ($j['status'] === 'approved' && !$isExp): ?><a class="btn btn-outline btn-sm" href="<?= url('job.php?id=' . (int)$j['id']) ?>" target="_blank">View live</a><?php endif; ?>
      <?php if ($j['apps'] > 0): ?><a class="btn btn-outline btn-sm" href="<?= url('employer/applicants.php?job=' . (int)$j['id']) ?>">Applicants</a><?php endif; ?>
      <?php if ($isExp || $j['status'] === 'closed' && $j['deadline'] < date('Y-m-d')): ?><a class="btn btn-primary btn-sm" href="<?= url('employer/post-job.php?id=' . (int)$j['id']) ?>">Renew</a>
      <?php else: ?><a class="btn btn-outline btn-sm" href="<?= url('employer/post-job.php?id=' . (int)$j['id']) ?>">Edit</a><?php endif; ?>
      <?php if ($canPost): ?><a class="btn btn-outline btn-sm" href="<?= url('employer/post-job.php?copy=' . (int)$j['id']) ?>">Duplicate</a><?php endif; ?>
      <?php if (in_array($j['status'], ['approved', 'pending'], true) && !$isExp): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="close"><input type="hidden" name="job_id" value="<?= (int)$j['id'] ?>">
          <button class="btn btn-outline btn-sm" type="submit" data-confirm="It will stop accepting applications." data-confirm-title="Close this job?" data-confirm-ok="Close job" data-confirm-tone="info">Close (filled)</button></form>
      <?php elseif ($j['status'] === 'closed' && $j['deadline'] >= date('Y-m-d')): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reopen"><input type="hidden" name="job_id" value="<?= (int)$j['id'] ?>"><button class="btn btn-primary btn-sm" type="submit">Reopen</button></form>
      <?php endif; ?>
      <?php if ((int)$j['apps'] === 0): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="job_id" value="<?= (int)$j['id'] ?>">
          <button class="btn btn-outline btn-sm btn-del" type="submit" data-confirm="It will be deleted permanently." data-confirm-title="Delete this job?" data-confirm-ok="Delete" data-confirm-tone="danger">Delete</button></form>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; endif; ?>
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>