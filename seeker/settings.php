<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/upload.php';
require_role('seeker');
$pdo = db(); $uid = (int)$_SESSION['uid'];
$errors = []; $tab = '';

$u = $pdo->prepare('SELECT email, password_hash FROM users WHERE id = ?');
$u->execute([$uid]);
$me = $u->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? ''; $tab = $act;
    if (!csrf_check()) { $errors[] = 'Your session expired. Please try again.'; }
    elseif (rate_count('settings', 15) >= 8) { $errors[] = 'Too many attempts. Please wait 15 minutes.'; }
    else {
        $cur = $_POST['current'] ?? '';
        if (!password_verify($cur, $me['password_hash'])) { rate_hit('settings'); $errors[] = 'Your current password is incorrect.'; }
        elseif ($act === 'password') {
            $new = $_POST['new'] ?? '';
            if ($pw_err = password_problem($new)) $errors[] = $pw_err;
            elseif ($new !== ($_POST['new2'] ?? '')) $errors[] = 'The two new passwords do not match.';
            else {
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
                session_regenerate_id(true);
                flash('success', 'Your password was changed.');
                redirect('seeker/settings.php');
            }
        } elseif ($act === 'email') {
            $em = strtolower(trim($_POST['email'] ?? ''));
            if (!filter_var($em, FILTER_VALIDATE_EMAIL) || strlen($em) > 190) $errors[] = 'Enter a valid email address.';
            else {
                $c = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
                $c->execute([$em, $uid]);
                if ($c->fetch()) $errors[] = 'That email is already used by another account.';
                else {
                    $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$em, $uid]);
                    flash('success', 'Your email was updated.');
                    redirect('seeker/settings.php');
                }
            }
        } elseif ($act === 'delete') {
            if (trim($_POST['confirm'] ?? '') !== 'DELETE') $errors[] = 'Type DELETE in capital letters to confirm.';
            else {
                $files = $pdo->prepare('SELECT resume_path FROM job_applications WHERE seeker_id = ? AND resume_path IS NOT NULL UNION SELECT resume_path FROM seeker_profiles WHERE user_id = ? AND resume_path IS NOT NULL');
                $files->execute([$uid, $uid]);
                $paths = $files->fetchAll(PDO::FETCH_COLUMN);
                $pdo->prepare('DELETE FROM users WHERE id = ? AND role = \'seeker\'')->execute([$uid]);
                foreach ($paths as $f) delete_private($f);
                $_SESSION = [];
                session_destroy(); session_start();
                flash('success', 'Your account was deleted.');
                redirect('index.php');
            }
        }
    }
}
$pageTitle = 'Settings';
require __DIR__ . '/../includes/dash_top.php';
?>
<div class="settings">
<header class="sg-head">
  <div>
    <h1 class="dash-title sg-title">Settings</h1>
    <p class="sg-sub">Change your password or email, or delete your account.</p>
  </div>
  <span class="sg-pill"><?= icon('user', 14) ?> <?= e($me['email']) ?></span>
</header>
<?php if ($errors): ?><div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="sg-grid">
  <form method="post" class="card form sg-card">
    <?= csrf_field() ?><input type="hidden" name="action" value="password">
    <h2 class="sg-h"><span class="sg-ico"><?= icon('lock', 16) ?></span> Change password</h2>
    <p class="small sg-note">Use at least 8 characters with uppercase, lowercase, a number and a symbol.</p>
    <label for="s-cur1">Current password</label><input id="s-cur1" name="current" type="password" autocomplete="current-password" required>
    <div class="row2">
      <div><label for="s-new">New password</label><input id="s-new" name="new" type="password" minlength="8" autocomplete="new-password" required></div>
      <div><label for="s-new2">Confirm new password</label><input id="s-new2" name="new2" type="password" autocomplete="new-password" required></div>
    </div>
    <button class="btn btn-primary" type="submit">Change password</button>
  </form>

  <form method="post" class="card form sg-card">
    <?= csrf_field() ?><input type="hidden" name="action" value="email">
    <h2 class="sg-h"><span class="sg-ico"><?= icon('send', 16) ?></span> Change email</h2>
    <p class="small sg-note">Current email: <b><?= e($me['email']) ?></b></p>
    <label for="s-email">New email</label><input id="s-email" name="email" type="email" maxlength="190" required>
    <label for="s-cur2">Current password</label><input id="s-cur2" name="current" type="password" autocomplete="current-password" required>
    <button class="btn btn-primary" type="submit">Update email</button>
  </form>
</div>

<form method="post" class="card form danger sg-danger" data-confirm="Your account will be deleted permanently. This cannot be undone." data-confirm-title="Delete your account?" data-confirm-ok="Delete account" data-confirm-tone="danger">
  <?= csrf_field() ?><input type="hidden" name="action" value="delete">
  <h2 class="sg-h sg-h-bad"><span class="sg-ico sg-ico-bad"><?= icon('flag', 16) ?></span> Delete account</h2>
  <p class="small sg-note">This permanently removes your profile, applications, saved jobs, and resume. It cannot be undone.</p>
  <div class="row2">
    <div><label for="s-conf">Type DELETE to confirm</label><input id="s-conf" name="confirm" autocomplete="off" required></div>
    <div><label for="s-cur3">Current password</label><input id="s-cur3" name="current" type="password" autocomplete="current-password" required></div>
  </div>
  <button class="btn btn-danger" type="submit">Delete my account</button>
</form>
</div>
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>
