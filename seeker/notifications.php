<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require_role('seeker');
$pdo = db(); $uid = (int)$_SESSION['uid'];

$q = $pdo->prepare('SELECT id, message, link, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 50');
$q->execute([$uid]);
$items = $q->fetchAll();
// Mark as read after loading, so the page still shows which were new
$pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')->execute([$uid]);

$pageTitle = 'Notifications';
require __DIR__ . '/../includes/dash_top.php';
$newCount = 0; foreach ($items as $n) if (!$n['is_read']) $newCount++;
?>
<div class="notif">
<header class="nt-head">
  <div>
    <h1 class="dash-title nt-title">Notifications</h1>
    <p class="nt-sub">Updates about your applications.</p>
  </div>
  <?php if ($items): ?>
  <span class="nt-pills">
    <span class="nt-total"><b><?= count($items) ?></b> <?= count($items) === 1 ? 'update' : 'updates' ?></span>
    <?php if ($newCount > 0): ?><span class="nt-pill"><b><?= $newCount ?></b> new</span><?php endif; ?>
  </span>
  <?php endif; ?>
</header>

<?php if (!$items): ?>
  <div class="card nt-empty">
    <span class="nt-empty-ico"><?= icon('bell', 26) ?></span>
    <h3>No notifications yet</h3>
    <p>You will see updates here when an employer reviews your application.</p>
    <a class="btn btn-primary btn-sm" href="<?= url('seeker/browse.php') ?>">Browse jobs</a>
  </div>
<?php else: ?>
  <div class="card nt-list">
    <?php foreach ($items as $n):
      $safe = $n['link'] && preg_match('#^[A-Za-z0-9_\-/.?=&]+$#', $n['link']); ?>
      <div class="nt-item<?= !$n['is_read'] ? ' is-new' : '' ?>">
        <span class="nt-ico" aria-hidden="true"><?= icon('bell', 18) ?></span>
        <div class="nt-body">
          <div class="nt-msg"><?= !$n['is_read'] ? '<span class="sr-only">New: </span>' : '' ?><?= $safe ? '<a href="' . url($n['link']) . '">' . e($n['message']) . '</a>' : e($n['message']) ?></div>
          <div class="nt-time"><?= e(date('M j, Y g:i A', strtotime($n['created_at']))) ?></div>
        </div>
        <?php if (!$n['is_read']): ?><span class="nt-tag">New</span><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>