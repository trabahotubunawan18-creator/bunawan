<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/verify_email.php';

if (is_logged_in()) redirect(dashboard_url($_SESSION['role']));

$errors = [];
$email = strtolower(trim((string)($_GET['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) $email = '';
$sent = false;
$devLink = null;   // only ever filled in when you are on the server computer itself

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (rate_count('resend_verify', 60) >= 5) {
        $errors[] = 'Too many requests from this device. Please try again in an hour.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        $errors[] = 'Enter a valid email address.';
    } else {
        rate_hit('resend_verify');
        $s = db()->prepare("SELECT id, full_name, role FROM users WHERE email = ? AND status = 'pending_email' AND role <> 'admin'");
        $s->execute([$email]);
        if ($u = $s->fetch()) {
            $r = issue_verification((int)$u['id'], (string)$u['full_name'], $email, (string)$u['role']);
            $devLink = $r['dev_link'];
        }
        // Same message whether or not the email needs verifying, so nobody can check who has an account.
        $sent = true;
    }
}
$pageTitle = 'Resend verification email';
$bodyClass = 'simple-page';
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/back_button.php';
?>
<div class="wrap back-row"><?= back_button('login.php', 'Back', false) ?></div>

<section class="simple">
  <div class="card simple-card">
    <span class="simple-ico"><?= icon('mail', 26) ?></span>
    <?php if ($sent): ?>
      <h1>Check your email</h1>
      <p class="simple-lead">If <b><?= e($email) ?></b> is waiting to be verified, we sent a new link. Older links no longer work.</p>
      <?php if ($devLink): ?>
        <div class="alert alert-info" role="status">
          <b>Local testing only.</b> The email could not be sent, so here is the link:
          <p style="margin:8px 0 0;word-break:break-all"><a href="<?= e($devLink) ?>"><?= e($devLink) ?></a></p>
        </div>
      <?php endif; ?>
      <p class="small">Nothing in your inbox? Look in the spam or junk folder (the email is from <b>Trabaho Tu Bunawan</b>), or check that you typed your email correctly.</p>
      <a class="btn btn-outline block" href="<?= url('login.php') ?>">Back to login</a>
    <?php else: ?>
      <h1>Resend verification email</h1>
      <p class="simple-lead">Enter the email you signed up with. We will send you a new link to confirm it.</p>
      <?php if ($errors): ?><div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
      <form method="post" class="form" novalidate>
        <?= csrf_field() ?>
        <label for="v-email">Email</label>
        <input id="v-email" name="email" type="email" maxlength="190" autocomplete="email" value="<?= e($email) ?>" required autofocus>
        <button class="btn btn-primary block" type="submit">Send verification link</button>
      </form>
      <p class="small center">Already verified? <a href="<?= url('login.php') ?>">Log in</a></p>
    <?php endif; ?>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
