<?php
// Step 2 of "Continue with Google": Google sends the visitor back here.
require __DIR__ . '/includes/google_auth.php';

function g_fail(string $msg, string $to = 'login.php'): never { flash('error', $msg); redirect($to); }

if (is_logged_in()) redirect(dashboard_url($_SESSION['role']));
if (!google_enabled()) g_fail('Google sign-in is not set up yet.');

// Check that this answer matches the sign-in we started
$state = (string)($_SESSION['g_state'] ?? '');
$mode  = (string)($_SESSION['g_mode'] ?? 'login');
$began = (int)($_SESSION['g_time'] ?? 0);
unset($_SESSION['g_state'], $_SESSION['g_mode'], $_SESSION['g_time']);

if (isset($_GET['error'])) g_fail('Google sign-in was cancelled.');
if ($state === '' || !hash_equals($state, (string)($_GET['state'] ?? '')) || time() - $began > 600) {
    g_fail('The Google sign-in expired. Please try again.');
}
$code = (string)($_GET['code'] ?? '');
if ($code === '') g_fail('Google sign-in did not finish. Please try again.');

// Swap the code for an access token, then ask Google who signed in
$tok = google_http('https://oauth2.googleapis.com/token', [
    'code' => $code, 'client_id' => GOOGLE_CLIENT_ID, 'client_secret' => GOOGLE_CLIENT_SECRET,
    'redirect_uri' => google_redirect_uri(), 'grant_type' => 'authorization_code',
]);
if (!$tok || empty($tok['access_token'])) {
    error_log('Google token exchange failed: ' . json_encode($tok));
    g_fail('We could not complete Google sign-in. Please try again.');
}
$info = google_http('https://openidconnect.googleapis.com/v1/userinfo', null, (string)$tok['access_token']);
$email = strtolower(trim((string)($info['email'] ?? '')));
$verified = ($info['email_verified'] ?? false) === true || ($info['email_verified'] ?? '') === 'true';
if (!$info || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || !$verified) {
    g_fail('We could not confirm your Google email address. Please try again or use email and password.');
}
$name = trim((string)($info['name'] ?? ''));
if ($name === '') $name = ucfirst(explode('@', $email)[0]);
$name = mb_substr($name, 0, 100);

$s = db()->prepare('SELECT * FROM users WHERE email = ?');
$s->execute([$email]);
$u = $s->fetch();

// ---- Someone already has an account with this email: log them in ----
if ($u) {
    if ($u['role'] === 'admin') g_fail('Admin accounts must log in with email and password.');
    if ($u['status'] === 'suspended') g_fail('This account has been suspended. Please contact us if you think this is a mistake.');
    if ($u['status'] === 'pending_email') {
        // Google already confirmed this email address, so there is nothing left to verify.
        db()->prepare("UPDATE users SET status = 'active', email_token = NULL WHERE id = ?")->execute([$u['id']]);
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['role'] = $u['role'];
    $_SESSION['name'] = $u['full_name'];
    db()->prepare('UPDATE users SET failed_logins = 0, locked_until = NULL, last_login = NOW() WHERE id = ?')->execute([$u['id']]);
    if ($mode !== 'login') flash('info', 'You already have an account with this email, so we logged you in.');
    redirect(dashboard_url($u['role']));
}

// ---- No account yet ----
if ($mode === 'employer') {
    // Employers still go through the full registration and admin review. Google only fills in step 1.
    $_SESSION['google_signup'] = ['email' => $email, 'name' => $name, 'time' => time()];
    redirect('register.php?role=employer');
}
if ($mode === 'login') {
    g_fail('We could not find an account for ' . $email . '. Create a job seeker account below, or use "I\'m hiring" to register as an employer.', 'login.php?tab=register');
}

// $mode === 'register': new job seeker
if (rate_count('register', 60) >= 5) g_fail('Too many sign-ups from this device. Please try again later.', 'login.php?tab=register');
rate_hit('register');
$pdo = db();
$pdo->beginTransaction();
$pdo->prepare("INSERT INTO users (role, full_name, email, phone, password_hash, status) VALUES ('seeker', ?, ?, NULL, ?, 'active')")
    ->execute([$name, $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);   // no password: they log in with Google
$uid = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO seeker_profiles (user_id) VALUES (?)')->execute([$uid]);
$pdo->commit();

session_regenerate_id(true);
$_SESSION['uid'] = $uid;
$_SESSION['role'] = 'seeker';
$_SESSION['name'] = $name;
db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$uid]);
flash('success', 'Welcome! Your account was created with Google. Add your mobile number in your profile so employers can reach you.');
redirect(dashboard_url('seeker'));
