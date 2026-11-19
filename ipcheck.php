<?php
// TEMPORARY diagnostic page. Delete it after the test.
// It only prints IP-related values for the person visiting it. No database, no secrets.
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

foreach (['REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_X_FORWARDED_PROTO', 'HTTP_HOST'] as $k) {
    echo str_pad($k, 26) . ($_SERVER[$k] ?? '(not set)') . "\n";
}

$auth = @file_get_contents(__DIR__ . '/includes/auth.php');
echo "\n";
if ($auth === false) {
    echo "includes/auth.php:         could not be read\n";
} else {
    echo str_pad('includes/auth.php', 26) . (strpos($auth, 'HTTP_CF_CONNECTING_IP') === false ? 'NEW version (fix is deployed)' : 'OLD version (fix is NOT deployed yet)') . "\n";
}

$conf = @file_get_contents('/etc/apache2/conf-enabled/security-headers.conf');
echo str_pad('security-headers.conf', 26) . ($conf === false ? 'not enabled (Dockerfile not updated yet)' : 'enabled') . "\n";
