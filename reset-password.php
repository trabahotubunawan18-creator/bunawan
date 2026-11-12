<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/password_reset.php';

if (is_logged_in()) redirect(dashboard_url($_SESSION['role']));

$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
$reset = find_reset($token);
$errors = [];

if ($reset && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass  = $_POST['password'] ?? '';
    $pass2 = $_POST['password2'] ?? '';
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (rate_count('reset', 15) >= 10) {
        $errors[] = 'Too many attempts. Please wait a few minutes and try again.';
    } else {
        if ($pw_err = password_problem($pass)) $errors[] = $pw_err;
        if ($pass !== $pass2) $errors[] = 'The two passwords do not match.';
        if (!$errors) {
            rate_hit('reset');
            $pdo = db();
            try {
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE users SET password_hash = ?, failed_logins = 0, locked_until = NULL WHERE id = ?')
                    ->execute([password_hash($pass, PASSWORD_DEFAULT), $reset['user_id']]);
                // Use up this link and any other open links for the same account.
                $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$reset['user_id']]);
                $pdo->commit();
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Password reset failed: ' . $ex->getMessage());
                $errors[] = 'Something went wrong. Please try again.';
            }
            if (!$errors) {
                flash('success', 'Your password was changed. You can log in now.');
                redirect('login.php');
            }
        }
    }
}
$pageTitle = 'Choose a new password';
$bodyClass = 'simple-page';
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/back_button.php';
?>
<div class="wrap back-row"><?= back_button('login.php', 'Back', false) ?></div>
<section class="simple">
  <div class="card simple-card">
    <?php if (!$reset): ?>
      <span class="simple-ico simple-ico-warn"><?= icon('clock', 26) ?></span>
      <h1>This link no longer works</h1>
      <p class="simple-lead">Reset links expire after 60 minutes and can only be used once. Request a new one and we will send it right away.</p>
      <a class="btn btn-primary block" href="<?= url('forgot-password.php') ?>">Get a new link</a>
      <a class="btn btn-outline block" href="<?= url('login.php') ?>">Back to login</a>
    <?php else: ?>
      <span class="simple-ico"><?= icon('lock', 26) ?></span>
      <h1>Choose a new password</h1>
      <p class="simple-lead">Use at least 8 characters with uppercase, lowercase, a number and a symbol.</p>
      <?php if ($errors): ?><div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
      <form method="post" class="form" novalidate>
        <?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
        <label for="n-pass">New password</label>
        <div class="pw"><input id="n-pass" name="password" type="password" minlength="8" autocomplete="new-password" required autofocus>
          <button type="button" class="pw-btn" data-toggle-pw="n-pass">Show</button></div>
        <div class="meter" aria-live="polite"><span id="n-meter"></span></div><small class="small" id="n-meter-text">At least 8 characters with uppercase, lowercase, a number and a symbol.</small>
        <label for="n-pass2">Confirm new password</label>
        <input id="n-pass2" name="password2" type="password" autocomplete="new-password" required>
        <button class="btn btn-primary block" type="submit">Save new password</button>
      </form>
      <script>
      (function(){var p=document.getElementById('n-pass'),b=document.getElementById('n-meter'),t=document.getElementById('n-meter-text');
      if(!p)return;p.addEventListener('input',function(){var v=p.value,s=0;
      if(v.length>=8)s++;if(/[A-Z]/.test(v)&&/[a-z]/.test(v))s++;if(/\d/.test(v))s++;if(/[^A-Za-z0-9]/.test(v))s++;
      b.style.width=(v?(s+1)*20:0)+'%';b.style.background=['#D64545','#D64545','#E89B0C','#2FB463','#1B7F45'][s];
      t.textContent=v?['Too weak','Weak','Fair','Good','Strong'][s]:'At least 8 characters with uppercase, lowercase, a number and a symbol.';});})();
      </script>
    <?php endif; ?>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
