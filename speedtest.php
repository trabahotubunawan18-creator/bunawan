<?php
// Admin-only speed check. It shows where the waiting time goes (the database or the server).
// Open it while logged in as admin, then DELETE this file when you are done.
require __DIR__ . '/includes/auth.php';
if (!is_logged_in() || ($_SESSION['role'] ?? '') !== 'admin') { http_response_code(403); exit('Admins only. Log in as admin first.'); }
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

function ms(float $from): float { return (microtime(true) - $from) * 1000; }
function f(float $v): string { return number_format($v, 0) . ' ms'; }

echo "SPEED CHECK\n===========\n";
echo "Database host: " . DB_HOST . ":" . DB_PORT . "\n";
echo "Server time:   " . date('Y-m-d H:i:s') . " (Philippine time)\n\n";

$t = microtime(true);
try { db(); } catch (Throwable $ex) { exit("Could not connect: " . $ex->getMessage()); }
$connect = ms($t);
echo "1) Opening the database connection (done once on every page click): " . f($connect) . "\n";

$times = [];
for ($i = 0; $i < 10; $i++) {
    $t = microtime(true);
    db()->query('SELECT 1')->fetchColumn();
    $times[] = ms($t);
}
$avg = array_sum($times) / count($times);
echo "2) One trip to the database and back (average of 10):                " . f($avg) . "  (fastest " . f(min($times)) . ", slowest " . f(max($times)) . ")\n";

$t = microtime(true);
db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
echo "3) A simple real query (count of users):                              " . f(ms($t)) . "\n\n";

$est = $connect + 10 * $avg;
echo "A typical dashboard page needs about 10 queries, so the database alone adds roughly " . f($est) . " to every click.\n\n";

echo "HOW TO READ THIS\n----------------\n";
echo "Line 2 under 40 ms   : the database is close to the server. Good.\n";
echo "Line 2 around 60-150 ms: the database is in another country. Every click pays that 10 times.\n";
echo "Line 2 over 150 ms   : the database is far away. This is probably your main slowness.\n";
echo "Line 1 over 600 ms   : opening a new connection on every click is expensive.\n";
echo "If all numbers are small but pages still take many seconds, the cause is the server waking up\n";
echo "(Render's free plan sleeps after 15 minutes without visitors) and not the database.\n";
