<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require_role('employer');
$pdo = db(); $uid = (int)$_SESSION['uid'];
$errors = [];

$u = $pdo->prepare('SELECT full_name, email, phone, password_hash, created_at, last_login FROM users WHERE id = ?');
$u->execute([$uid]);
$me = $u->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    if (!csrf_check()) { $errors[] = 'Your session expired. Please try again.'; }
    elseif (rate_count('settings', 15) >= 8) { $errors[] = 'Too many attempts. Please wait 15 minutes.'; }
    elseif (!password_verify($_POST['current'] ?? '', $me['password_hash'])) {
        rate_hit('settings'); $errors[] = 'Your current password is incorrect.';
    }
    elseif ($act === 'password') {
        $new = $_POST['new'] ?? '';
        if (strlen($new) < 8 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) $errors[] = 'New password must be at least 8 characters with a letter and a number.';
        elseif ($new !== ($_POST['new2'] ?? '')) $errors[] = 'The two new passwords do not match.';
        elseif (password_verify($new, $me['password_hash'])) $errors[] = 'Your new password must be different from the current one.';
        else {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
            session_regenerate_id(true);
            $pdo->prepare('INSERT INTO notifications (user_id, message, link) VALUES (?,?,?)')->execute([$uid, 'Your password was changed. If this was not you, contact us right away.', 'employer/settings.php']);
            flash('success', 'Your password was changed.');
            redirect('employer/settings.php');
        }
    } elseif ($act === 'email') {
        $em = strtolower(trim($_POST['email'] ?? ''));
        if (!filter_var($em, FILTER_VALIDATE_EMAIL) || strlen($em) > 190) $errors[] = 'Enter a valid email address.';
        elseif ($em === strtolower($me['email'])) $errors[] = 'That is already your email.';
        else {
            $c = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
            $c->execute([$em, $uid]);
            if ($c->fetch()) $errors[] = 'That email is already used by another account.';
            else {
                $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$em, $uid]);
                flash('success', 'Your email was updated.');
                redirect('employer/settings.php');
            }
        }
    } elseif ($act === 'contact') {
        $name = trim(preg_replace('/\s+/', ' ', $_POST['full_name'] ?? ''));
        $phone = preg_replace('/[\s\-()]/', '', $_POST['phone'] ?? '');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) $errors[] = 'Enter your full name (2 to 100 characters).';
        if (!preg_match('/^(09|\+639)\d{9}$/', $phone)) $errors[] = 'Enter a valid mobile number, like 09123456789.';
        if (!$errors) {
            $pdo->prepare('UPDATE users SET full_name = ?, phone = ? WHERE id = ?')->execute([$name, $phone, $uid]);
            $_SESSION['name'] = $name;
            flash('success', 'Your contact details were updated.');
            redirect('employer/settings.php');
        }
    }
    if ($errors) { $me['full_name'] = trim($_POST['full_name'] ?? $me['full_name']); }
}

$pageTitle = 'Settings';
require __DIR__ . '/../includes/emp_top.php';
?>
<div class="emp-page emp-settings">
  <div class="emp-hero">
    <div>
      <h1>Settings</h1>
      <p>Manage your account details, email, and password. Company details are in <a href="<?= url('employer/company-profile.php') ?>">Company profile</a>.</p>
    </div>
    <span class="emp-hero-pill"><?= icon('shield', 15) ?> Secure account</span>
  </div>

  <?php if ($errors): ?><div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

  <div class="statcards emp-stats">
    <div class="card statcard"><span class="si si-green"><?= icon('user', 22) ?></span><div><strong style="font-size:18px"><?= e($me['full_name']) ?></strong><span>Account owner</span></div></div>
    <div class="card statcard"><span class="si si-leaf"><?= icon('calendar', 22) ?></span><div><strong style="font-size:18px"><?= e(date('M j, Y', strtotime($me['created_at']))) ?></strong><span>Member since</span></div></div>
    <div class="card statcard"><span class="si si-gold"><?= icon('lock', 22) ?></span><div><strong style="font-size:18px"><?= $me['last_login'] ? e(date('M j, g:i A', strtotime($me['last_login']))) : 'This session' ?></strong><span>Last login</span></div></div>
  </div>

  <div class="duo">
    <form method="post" class="card panel-card form emp-panel">
      <?= csrf_field() ?><input type="hidden" name="action" value="contact">
      <div class="emp-panel-head">
        <span class="emp-panel-ico tone-green"><?= icon('user', 18) ?></span>
        <div><h2>Contact details</h2><p>Applicants and admins may use these to reach you.</p></div>
      </div>
      <div class="row2">
        <div><label for="s-name">Full name</label><input id="s-name" name="full_name" maxlength="100" value="<?= e($me['full_name']) ?>" required></div>
        <div><label for="s-phone">Mobile number</label><input id="s-phone" name="phone" inputmode="tel" maxlength="16" value="<?= e($_POST['phone'] ?? $me['phone']) ?>" placeholder="09123456789" required></div>
      </div>
      <div class="set-confirm">
        <div><label for="s-cur0">Current password</label><input id="s-cur0" name="current" type="password" autocomplete="current-password" placeholder="To confirm it is you" required></div>
        <button class="btn btn-primary" type="submit">Save</button>
      </div>
    </form>

    <form method="post" class="card panel-card form emp-panel">
      <?= csrf_field() ?><input type="hidden" name="action" value="email">
      <div class="emp-panel-head">
        <span class="emp-panel-ico tone-gold"><?= icon('mail', 18) ?></span>
        <div><h2>Email address</h2><p>Current: <b><?= e($me['email']) ?></b></p></div>
      </div>
      <label for="s-email">New email</label><input id="s-email" name="email" type="email" maxlength="190" required>
      <div class="set-confirm">
        <div><label for="s-cur2">Current password</label><input id="s-cur2" name="current" type="password" autocomplete="current-password" placeholder="To confirm it is you" required></div>
        <button class="btn btn-primary" type="submit">Update</button>
      </div>
    </form>
  </div>

  <form method="post" class="card panel-card form emp-panel">
    <?= csrf_field() ?><input type="hidden" name="action" value="password">
    <div class="emp-panel-head">
      <span class="emp-panel-ico tone-leaf"><?= icon('lock', 18) ?></span>
      <div><h2>Change password</h2><p>Use at least 8 characters with a letter and a number.</p></div>
    </div>
    <div class="set-pw">
      <div><label for="s-cur1">Current password</label><input id="s-cur1" name="current" type="password" autocomplete="current-password" required></div>
      <div><label for="s-new">New password</label><input id="s-new" name="new" type="password" minlength="8" autocomplete="new-password" required></div>
      <div><label for="s-new2">Confirm new password</label><input id="s-new2" name="new2" type="password" autocomplete="new-password" required></div>
      <button class="btn btn-primary" type="submit">Change</button>
    </div>
  </form>

  <div class="card panel-card emp-close">
    <span class="emp-panel-ico tone-gold"><?= icon('building', 18) ?></span>
    <div>
      <h2>Need to close your account?</h2>
      <p>Your jobs, applicants, and verification records are linked to your account, so closing it is handled by our team. <a href="<?= url('contact.php') ?>">Contact us</a> and we will help you.</p>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>