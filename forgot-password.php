<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/password_reset.php';

if (is_logged_in()) redirect(dashboard_url($_SESSION['role']));

$errors = [];
$email = '';
$sent = false;
$devLink = null;   // only ever filled in when you are on the server computer itself
$mailError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (rate_count('forgot', 60) >= 5) {
        $errors[] = 'Too many reset requests from this device. Please try again in an hour.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        $errors[] = 'Enter a valid email address.';
    } else {
        rate_hit('forgot');
        reset_table();
        $s = db()->prepare("SELECT id, full_name, status FROM users WHERE email = ? AND role <> 'admin'");
        $s->execute([$email]);
        $u = $s->fetch();
        if ($u && $u['status'] !== 'suspended') {
            db()->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$u['id']]);
            $token = bin2hex(random_bytes(32));
            db()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + make_interval(mins => CAST(? AS integer)))')
                ->execute([$u['id'], hash('sha256', $token), reset_minutes()]);
            $link = absolute_url('reset-password.php?token=' . $token);
            // A neat HTML email (with a plain-text copy for mail apps that cannot show HTML).
            $mail   = build_reset_email((string)$u['full_name'], $email, $link);
            $mailed = send_site_mail($email, $mail['subject'], $mail['text'], $mail['html'], (string)$u['full_name']);
            // XAMPP cannot send email by default, so on your own computer we show the link here instead.
            if (!$mailed) $mailError = mail_last_error();
            if (!$mailed && is_local_request()) $devLink = $link;
        }
        // Same message whether or not the email exists, so nobody can check who has an account.
        $sent = true;
    }
}
$pageTitle = 'Forgot password';
$bodyClass = 'simple-page';
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/back_button.php';
?>
<div class="wrap back-row"><?= back_button('login.php', 'Back', false) ?></div>

<section class="simple">
  <div class="card simple-card">
    <span class="simple-ico"><?= icon('lock', 26) ?></span>
    <?php if ($sent): ?>
      <h1>Check your email</h1>
      <p class="simple-lead">If an account exists for <b><?= e($email) ?></b>, we sent a link to reset the password. The link works for <?= (int)reset_minutes() ?> minutes and can be used once.</p>
      <?php if ($devLink): ?>
        <div class="alert alert-info" role="status">
          <b>Local testing only.</b> The email could not be sent<?= $mailError ? ' (' . e($mailError) . ')' : '' ?>, so here is the link:
          <p style="margin:8px 0 0;word-break:break-all"><a href="<?= e($devLink) ?>"><?= e($devLink) ?></a></p>
        </div>
      <?php endif; ?>
      <p class="small">Nothing in your inbox? Look in the spam or junk folder (the email is from <b>Trabaho Tu Bunawan</b>), wait a minute or two, or <a href="<?= url('forgot-password.php') ?>">try again</a>.</p>
      <a class="btn btn-outline block" href="<?= url('login.php') ?>">Back to login</a>
    <?php else: ?>
      <h1>Forgot your password?</h1>
      <p class="simple-lead">Enter the email you registered with. We will send you a link to choose a new password.</p>
      <?php if ($errors): ?><div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
      <form method="post" class="form" novalidate>
        <?= csrf_field() ?>
        <label for="f-email">Email</label>
        <input id="f-email" name="email" type="email" maxlength="190" autocomplete="email" value="<?= e($email) ?>" required autofocus>
        <button class="btn btn-primary block" type="submit">Send reset link</button>
      </form>
      <p class="small center">Remembered it? <a href="<?= url('login.php') ?>">Log in</a></p>
    <?php endif; ?>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
