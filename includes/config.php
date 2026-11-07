<?php
// Site settings. This file is safe to upload to GitHub: it holds NO passwords.
// Put your real passwords in includes/config.local.php on your own computer (that file is git-ignored),
// or set them as environment variables on your web host. See includes/config.local.example.php.

// Philippine time, so dates in PHP and in the database always agree.
date_default_timezone_set('Asia/Manila');

// Optional private settings for this computer (database password, Gmail App Password...).
if (is_file(__DIR__ . '/config.local.php')) require __DIR__ . '/config.local.php';

// Uses config.local.php first, then an environment variable, then the default shown here.
function cfg_define(string $name, $default): void {
    if (defined($name)) return;
    $v = getenv($name);
    if ($v === false) { define($name, $default); return; }
    if (is_bool($default))     define($name, filter_var($v, FILTER_VALIDATE_BOOLEAN));
    elseif (is_int($default))  define($name, (int)$v);
    else                       define($name, $v);
}

// ---------- Database: Supabase (PostgreSQL) ----------
// Supabase dashboard -> Connect -> "Session pooler": copy the host, port, user and your database password.
cfg_define('DB_HOST', 'localhost');
cfg_define('DB_PORT', '5432');
cfg_define('DB_NAME', 'postgres');
cfg_define('DB_USER', 'postgres');
cfg_define('DB_PASS', '');
cfg_define('DB_SSLMODE', 'prefer');      // Supabase needs 'require'

// Folder name inside htdocs. Use '' if the site is at the web root (most web hosts).
// BASE_URL: '/bunawan' on XAMPP. On a host, set the BASE_URL variable to / (or leave it empty).
if (!defined('BASE_URL')) { $b = getenv('BASE_URL'); define('BASE_URL', $b === false ? '/bunawan' : rtrim($b, '/')); }

cfg_define('SESSION_IDLE', 1800);        // log out after 30 minutes of no activity
cfg_define('REQUIRE_EMAIL_VERIFY', false); // set true once email sending works
cfg_define('MAX_FAILED_LOGINS', 5);      // lock the account after this many wrong passwords
cfg_define('LOCK_MINUTES', 15);

// ---------- Email (needed so "Forgot password" really sends an email) ----------
// Easiest option: a Gmail account with an App Password.
//   1. Turn on 2-Step Verification:  https://myaccount.google.com/security
//   2. Create an App Password:       https://myaccount.google.com/apppasswords
//   3. Put the 16 letters in SMTP_PASS and your Gmail address in SMTP_USER (in config.local.php, not here).
cfg_define('SMTP_HOST', 'smtp.gmail.com');
cfg_define('SMTP_PORT', 587);            // 587 with 'tls', or 465 with 'ssl'
cfg_define('SMTP_SECURE', 'tls');
cfg_define('SMTP_USER', '');
cfg_define('SMTP_PASS', '');
cfg_define('SMTP_FROM', '');             // leave empty to use SMTP_USER (Gmail only sends from your own address)
cfg_define('SMTP_FROM_NAME', 'Trabaho Tu Bunawan');
cfg_define('SMTP_VERIFY_TLS', true);

// On a live server, set your real address so links in emails are correct (config.local.php or the SITE_URL variable).
if (!defined('SITE_URL') && getenv('SITE_URL')) define('SITE_URL', getenv('SITE_URL'));

// Show errors while developing. On a live server set APP_DEBUG to 0 so visitors never see error details.
cfg_define('APP_DEBUG', true);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
error_reporting(E_ALL);