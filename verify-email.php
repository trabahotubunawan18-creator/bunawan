<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

$token = (string)($_GET['token'] ?? '');
$ok = false;
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $s = db()->prepare("SELECT id FROM users WHERE email_token = ? AND status = 'pending_email'");
    $s->execute([hash('sha256', $token)]);   // only the hash is stored
    if ($u = $s->fetch()) {
        db()->prepare("UPDATE users SET status = 'active', email_token = NULL WHERE id = ?")->execute([$u['id']]);
        $ok = true;
    }
}
$pageTitle = 'Verify email';
$bodyClass = 'simple-page';
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/back_button.php';
?>
<div class="wrap back-row"><?= back_button('login.php', 'Back', false) ?></div>
<section class="simple">
  <div class="card simple-card">
    <?php if ($ok): ?>
      <span class="simple-ico simple-ico-ok"><?= icon('check', 26) ?></span>
      <h1>Email verified</h1>
      <p class="simple-lead">Thank you. Your email address is confirmed and your account is ready. Log in to continue.</p>
      <a class="btn btn-primary block" href="<?= url('login.php') ?>">Log in</a>
    <?php else: ?>
      <span class="simple-ico simple-ico-warn"><?= icon('mail', 26) ?></span>
      <h1>We could not verify this link</h1>
      <p class="simple-lead">The link may be incomplete, or it may already have been used. If you can log in, your email is already verified.</p>
      <a class="btn btn-primary block" href="<?= url('login.php') ?>">Go to login</a>
      <a class="btn btn-outline block" href="<?= url('contact.php') ?>">Contact us for help</a>
    <?php endif; ?>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
