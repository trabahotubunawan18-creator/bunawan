<?php
// Copy this file to config.local.php and fill it in. config.local.php is git-ignored, so it never goes to GitHub.
// On a web host you can skip the file and set the same names as environment variables instead.

// Supabase -> Connect -> Session pooler
define('DB_HOST', 'aws-0-xx-xxxx-x.pooler.supabase.com');
define('DB_PORT', '5432');
define('DB_NAME', 'postgres');
define('DB_USER', 'postgres.yourprojectref');
define('DB_PASS', 'your-database-password');
define('DB_SSLMODE', 'require');

// define('BASE_URL', '');                          // '' on a host, '/bunawan' on XAMPP
// define('SITE_URL', 'https://your-site.example'); // used in the links inside emails
// define('APP_DEBUG', false);                      // false on a live site

define('SMTP_USER', 'yourname@gmail.com');
define('SMTP_PASS', 'xxxx xxxx xxxx xxxx');         // Gmail App Password
