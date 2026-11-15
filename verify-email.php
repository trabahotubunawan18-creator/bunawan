<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/verify_email.php';

$token  = (string)($_GET['token'] ?? '');
$result = complete_registration($token);   // 'ok', 'exists', 'invalid' or 'error'
if ($result === 'invalid' && preg_match('/^[a-f0-9]{64}$/', $token)) {
    // LEGACY: accounts made by the old flow that are still 'pending_email'. Remove once they are cleaned up.
    $s = db()->prepare("SELECT id FROM users WHERE email_token = ? AND status = 'pending_email'");
    $s->execute([hash('sha256', $token)]);
    if ($u = $s->fetch()) {
        db()->prepare("UPDATE users SET status = 'active', email_token = NULL WHERE id = ?")->execute([$u['id']]);
        $result = 'ok';
    }
}
$ok = $result === 'ok';
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
      <p class="simple-lead">Thank you. Your email address is confirmed and your account has been created. Log in to continue.</p>
      <a class="btn btn-primary block" href="<?= url('login.php') ?>">Log in</a>
    <?php elseif ($result === 'exists'): ?>
      <span class="simple-ico simple-ico-ok"><?= icon('check', 26) ?></span>
      <h1>This email already has an account</h1>
      <p class="simple-lead">Your email is already confirmed, so you can just log in.</p>
      <a class="btn btn-primary block" href="<?= url('login.php') ?>">Log in</a>
    <?php elseif ($result === 'error'): ?>
      <span class="simple-ico simple-ico-warn"><?= icon('mail', 26) ?></span>
      <h1>Something went wrong</h1>
      <p class="simple-lead">We could not finish creating your account. Please open the link again in a moment. If it keeps happening, contact us.</p>
      <a class="btn btn-primary block" href="<?= url('contact.php') ?>">Contact us for help</a>
    <?php else: ?>
      <span class="simple-ico simple-ico-warn"><?= icon('mail', 26) ?></span>
      <h1>We could not verify this link</h1>
      <p class="simple-lead">The link may be incomplete, already used, or older than <?= (int)PENDING_HOURS ?> hours (an unconfirmed sign-up is removed after that). If you can log in, your email is already verified.</p>
      <a class="btn btn-primary block" href="<?= url('resend-verification.php') ?>">Send me a new link</a>
      <a class="btn btn-outline block" href="<?= url('login.php?tab=register') ?>">Register again</a>
      <a class="btn btn-outline block" href="<?= url('login.php') ?>">Go to login</a>
    <?php endif; ?>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
