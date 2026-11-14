<?php
// Step 1 of "Continue with Google": sends the visitor to Google's sign-in page.
require __DIR__ . '/includes/google_auth.php';

if (is_logged_in()) redirect(dashboard_url($_SESSION['role']));
if (!google_enabled()) { flash('error', 'Google sign-in is not set up yet.'); redirect('login.php'); }
if (rate_count('google_start', 15) >= 20) { flash('error', 'Too many attempts. Please try again in a few minutes.'); redirect('login.php'); }
rate_hit('google_start');

$mode = $_GET['mode'] ?? 'login';
if (!in_array($mode, ['login', 'register', 'employer'], true)) $mode = 'login';

$state = bin2hex(random_bytes(16));                 // proves the answer from Google belongs to this visit
$_SESSION['g_state'] = $state;
$_SESSION['g_mode']  = $mode;
$_SESSION['g_time']  = time();

header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id'     => GOOGLE_CLIENT_ID,
    'redirect_uri'  => google_redirect_uri(),
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'state'         => $state,
    'prompt'        => 'select_account',
]));
exit;
