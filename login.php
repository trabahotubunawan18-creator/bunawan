<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

if (is_logged_in()) redirect(dashboard_url($_SESSION['role']));

$errors = [];
$email = '';
$tab = ($_GET['tab'] ?? 'login') === 'register' ? 'register' : 'login';
$old = ['name' => '', 'email' => '', 'phone' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (($_POST['action'] ?? '') === 'login') {
        $tab = 'login';
        $email = strtolower(trim($_POST['email'] ?? ''));
        $pass  = $_POST['password'] ?? '';

        if (rate_count('login', 15) >= 10) {
            $errors[] = 'Too many login attempts. Please wait 15 minutes and try again.';
        } elseif ($email === '' || $pass === '') {
            $errors[] = 'Please enter your email and password.';
        } else {
            $s = db()->prepare('SELECT * FROM users WHERE email = ?');
            $s->execute([$email]);
            $u = $s->fetch();
            $generic = 'Incorrect email or password.';

            if ($u && $u['locked_until'] && strtotime($u['locked_until']) > time()) {
                $errors[] = 'This account is temporarily locked after too many wrong passwords. Try again in ' . LOCK_MINUTES . ' minutes.';
            } elseif (!$u || $u['role'] === 'admin' || !password_verify($pass, $u['password_hash'])) {
                rate_hit('login');
                if ($u && $u['role'] !== 'admin') {
                    $fails = $u['failed_logins'] + 1;
                    $lock = $fails >= MAX_FAILED_LOGINS ? date('Y-m-d H:i:s', time() + LOCK_MINUTES * 60) : null;
                    db()->prepare('UPDATE users SET failed_logins = ?, locked_until = ? WHERE id = ?')
                        ->execute([$lock ? 0 : $fails, $lock, $u['id']]);
                }
                $errors[] = $generic;
            } elseif ($u['status'] === 'suspended') {
                $errors[] = 'This account has been suspended. Please contact us if you think this is a mistake.';
            } elseif ($u['status'] === 'pending_email') {
                $errors[] = 'Please verify your email first. Check your inbox for the verification link.';
            } else {
                session_regenerate_id(true);
                $_SESSION['uid'] = (int)$u['id'];
                $_SESSION['role'] = $u['role'];
                $_SESSION['name'] = $u['full_name'];
                db()->prepare('UPDATE users SET failed_logins = 0, locked_until = NULL, last_login = NOW() WHERE id = ?')->execute([$u['id']]);
                redirect(dashboard_url($u['role']));
            }
        }
    } elseif (($_POST['action'] ?? '') === 'register') {
        $tab = 'register';
        $old['name']  = trim($_POST['name'] ?? '');
        $old['email'] = strtolower(trim($_POST['email'] ?? ''));
        $old['phone'] = preg_replace('/[\s-]/', '', $_POST['phone'] ?? '');
        $pass = $_POST['password'] ?? '';
        $pass2 = $_POST['password2'] ?? '';

        if (rate_count('register', 60) >= 5) {
            $errors[] = 'Too many sign-ups from this device. Please try again later.';
        } else {
            if (mb_strlen($old['name']) < 2 || mb_strlen($old['name']) > 100) $errors[] = 'Enter your full name (2 to 100 characters).';
            if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || strlen($old['email']) > 190) $errors[] = 'Enter a valid email address.';
            if (!preg_match('/^(09|\+639)\d{9}$/', $old['phone'])) $errors[] = 'Enter a valid Philippine mobile number, like 09123456789.';
            if (strlen($pass) < 8 || !preg_match('/[A-Za-z]/', $pass) || !preg_match('/\d/', $pass)) $errors[] = 'Password must be at least 8 characters and include a letter and a number.';
            if ($pass !== $pass2) $errors[] = 'The two passwords do not match.';
            if (empty($_POST['agree'])) $errors[] = 'Please accept the Terms and Privacy Policy to continue.';

            if (!$errors) {
                $s = db()->prepare('SELECT id FROM users WHERE email = ?');
                $s->execute([$old['email']]);
                if ($s->fetch()) {
                    $errors[] = 'That email is already registered. Try logging in instead.';
                } else {
                    rate_hit('register');
                    $status = REQUIRE_EMAIL_VERIFY ? 'pending_email' : 'active';
                    $token = REQUIRE_EMAIL_VERIFY ? bin2hex(random_bytes(32)) : null;
                    $pdo = db();
                    $pdo->beginTransaction();
                    $pdo->prepare("INSERT INTO users (role, full_name, email, phone, password_hash, status, email_token) VALUES ('seeker', ?, ?, ?, ?, ?, ?)")
                        ->execute([$old['name'], $old['email'], $old['phone'], password_hash($pass, PASSWORD_DEFAULT), $status, $token]);
                    $pdo->prepare('INSERT INTO seeker_profiles (user_id) VALUES (?)')->execute([$pdo->lastInsertId()]);
                    $pdo->commit();
                    // TODO (later step): send $token by email when REQUIRE_EMAIL_VERIFY is true
                    flash('success', 'Account created. You can now log in.');
                    redirect('login.php');
                }
            }
        }
    }
}
$pageTitle = 'Login or Register';
$bodyClass = 'auth-page';
require __DIR__ . '/includes/header.php';
?>
<section class="auth">
  <aside class="auth-side">
    <img src="<?= url('assets/img/logo-white.svg') ?>" alt="Trabaho Tu Bunawan" width="220" onerror="this.style.display='none'">
    <h1>Where Local Talent Meets Local Opportunity.</h1>
    <p>Find work close to home, or find the right person for your vacancy, all in Bunawan, Agusan del Sur.</p>
    <ul>
      <li>Apply with a short form. No resume needed unless the employer asks.</li>
      <li>Every employer is checked by our admin team.</li>
      <li>Free for job seekers.</li>
    </ul>
  </aside>

  <div class="auth-main">
    <div class="card auth-card">
      <div class="tabs" role="tablist">
        <button class="tab<?= $tab === 'login' ? ' active' : '' ?>" role="tab" data-tab="login" aria-selected="<?= $tab === 'login' ? 'true' : 'false' ?>">Login</button>
        <button class="tab<?= $tab === 'register' ? ' active' : '' ?>" role="tab" data-tab="register" aria-selected="<?= $tab === 'register' ? 'true' : 'false' ?>">Register</button>
      </div>

      <?php if ($errors): ?>
        <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
      <?php endif; ?>

      <form id="panel-login" class="panel<?= $tab === 'login' ? ' show' : '' ?>" method="post" novalidate>
        <?= csrf_field() ?><input type="hidden" name="action" value="login">
        <label for="l-email">Email</label>
        <input id="l-email" name="email" type="email" autocomplete="email" value="<?= e($email) ?>" required>
        <label for="l-pass">Password</label>
        <div class="pw"><input id="l-pass" name="password" type="password" autocomplete="current-password" required>
          <button type="button" class="pw-btn" data-toggle-pw="l-pass">Show</button></div>
        <p class="small"><a href="<?= url('forgot-password.php') ?>">Forgot your password?</a></p>
        <button class="btn btn-primary block" type="submit">Log in</button>
        <p class="small center">New here? <a href="#" data-tab-link="register">Create a job seeker account</a></p>
      </form>

      <form id="panel-register" class="panel<?= $tab === 'register' ? ' show' : '' ?>" method="post" novalidate>
        <?= csrf_field() ?><input type="hidden" name="action" value="register">
        <div class="role-pick">
          <div class="role on"><b>I'm looking for a job</b><small>Register below</small></div>
          <a class="role" href="<?= url('register.php?role=employer') ?>"><b>I'm hiring</b><small>Employer registration</small></a>
        </div>
        <label for="r-name">Full name</label>
        <input id="r-name" name="name" autocomplete="name" maxlength="100" value="<?= e($old['name']) ?>" required>
        <label for="r-email">Email</label>
        <input id="r-email" name="email" type="email" autocomplete="email" value="<?= e($old['email']) ?>" required>
        <label for="r-phone">Mobile number</label>
        <input id="r-phone" name="phone" type="tel" inputmode="numeric" placeholder="09123456789" value="<?= e($old['phone']) ?>" required>
        <label for="r-pass">Password</label>
        <div class="pw"><input id="r-pass" name="password" type="password" autocomplete="new-password" minlength="8" required>
          <button type="button" class="pw-btn" data-toggle-pw="r-pass">Show</button></div>
        <div class="meter" aria-live="polite"><span id="meter-bar"></span></div><small id="meter-text" class="small">At least 8 characters with a letter and a number.</small>
        <label for="r-pass2">Confirm password</label>
        <input id="r-pass2" name="password2" type="password" autocomplete="new-password" required>
        <label class="check-row"><input type="checkbox" name="agree" value="1"> <span>I agree to the <a href="<?= url('terms.php') ?>">Terms</a> and <a href="<?= url('privacy.php') ?>">Privacy Policy</a>.</span></label>
        <button class="btn btn-primary block" type="submit">Create account</button>
        <p class="small center">Already registered? <a href="#" data-tab-link="login">Log in</a></p>
      </form>
    </div>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>