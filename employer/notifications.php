<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require_role('employer');
$pdo = db(); $uid = (int)$_SESSION['uid'];
$q = $pdo->prepare('SELECT id, message, link, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 50');
$q->execute([$uid]);
$items = $q->fetchAll();
$newCount = 0; foreach ($items as $n) if (!$n['is_read']) $newCount++;
$pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')->execute([$uid]);

// Group by day
$groups = [];
$today = date('Y-m-d'); $yest = date('Y-m-d', strtotime('-1 day'));
foreach ($items as $n) {
    $d = date('Y-m-d', strtotime($n['created_at']));
    $label = $d === $today ? 'Today' : ($d === $yest ? 'Yesterday' : date('F j, Y', strtotime($n['created_at'])));
    $groups[$label][] = $n;
}

// Icon, color and label based on where the notification leads
function emp_notif_kind(?string $link, string $msg): array {
    $l = (string)$link;
    if (strpos($l, 'verification') !== false) return ['shield', 'green', 'Verification'];
    if (strpos($l, 'premium') !== false)      return ['star', 'gold', 'Premium'];
    if (strpos($l, 'applicants') !== false)   return ['users', 'leaf', 'Applicant'];
    if (strpos($l, 'my-jobs') !== false)      return ['briefcase', 'gold', 'Job post'];
    if (strpos($l, 'settings') !== false)     return ['lock', 'green', 'Account'];
    return ['bell', 'green', 'Update'];
}

$pageTitle = 'Notifications';
require __DIR__ . '/../includes/emp_top.php';
?>
<div class="emp-page">
  <div class="emp-hero">
    <div>
      <h1>Notifications</h1>
      <p>New applicants, approvals, and verification updates.</p>
    </div>
    <span class="emp-hero-pill"><?= icon('bell', 15) ?> <?= $newCount > 0 ? $newCount . ' new' : 'All caught up' ?></span>
  </div>

  <?php if (!$items): ?>
    <div class="card emp-empty">
      <span class="emp-empty-ico"><?= icon('bell', 30) ?></span>
      <h3>No notifications yet</h3>
      <p>You will see updates here when something needs your attention.</p>
    </div>
  <?php else: ?>
    <?php foreach ($groups as $label => $list): ?>
      <h2 class="emp-day"><?= e($label) ?></h2>
      <div class="card emp-notif-list">
        <?php foreach ($list as $n): [$ic, $tone, $kind] = emp_notif_kind($n['link'], $n['message']);
          $safe = $n['link'] && preg_match('#^[A-Za-z0-9_\-/.?=&]+$#', $n['link']); ?>
          <div class="emp-notif<?= $n['is_read'] ? '' : ' is-new' ?>">
            <span class="emp-notif-ico tone-<?= e($tone) ?>"><?= icon($ic, 20) ?></span>
            <div class="emp-notif-body">
              <div class="emp-notif-top">
                <span class="emp-notif-kind"><?= e($kind) ?></span>
                <?php if (!$n['is_read']): ?><span class="emp-new-pill">New</span><?php endif; ?>
              </div>
              <?php if ($safe): ?>
                <a class="emp-notif-msg" href="<?= url($n['link']) ?>"><?= e($n['message']) ?></a>
              <?php else: ?>
                <span class="emp-notif-msg"><?= e($n['message']) ?></span>
              <?php endif; ?>
              <span class="emp-notif-time"><?= icon('clock', 13) ?> <?= e(date('g:i A', strtotime($n['created_at']))) ?> &middot; <?= e(date('M j, Y', strtotime($n['created_at']))) ?></span>
            </div>
            <?php if ($safe): ?><a class="emp-notif-go" href="<?= url($n['link']) ?>" aria-label="Open"><?= icon('arrow', 16) ?></a><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>