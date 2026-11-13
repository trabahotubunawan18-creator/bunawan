<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'httponly' => true,
        'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}
if (!empty($_SESSION['uid']) && isset($_SESSION['last']) && time() - $_SESSION['last'] > SESSION_IDLE) {
    session_unset(); session_destroy(); session_start();
    $_SESSION['flash'] = ['info', 'You were logged out because of inactivity. Please log in again.'];
}
$_SESSION['last'] = time();

// ---------- Output and redirects ----------
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function url(string $path = ''): string { return BASE_URL . '/' . ltrim($path, '/'); }
function redirect(string $path): never { header('Location: ' . url($path)); exit; }

// ---------- Flash messages ----------
function flash(string $type, string $msg): void { $_SESSION['flash'] = [$type, $msg]; }
function get_flash(): ?array { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }

// ---------- CSRF ----------
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): bool {
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']);
}

// ---------- Login state and roles ----------
function is_logged_in(): bool { return !empty($_SESSION['uid']); }
function dashboard_url(string $role): string {
    return ['seeker' => 'seeker/index.php', 'employer' => 'employer/index.php', 'admin' => 'admin/index.php'][$role] ?? 'index.php';
}
function require_login(): void {
    if (!is_logged_in()) { flash('info', 'Please log in to continue.'); redirect('login.php'); }
}
function require_role(string $role): void {
    require_login();
    if (($_SESSION['role'] ?? '') !== $role) { http_response_code(403); exit('You do not have access to this page.'); }
}

// ---------- Rate limiting (uses the rate_limits table) ----------
function client_ip(): string {
    // Behind Render's proxy, REMOTE_ADDR can be the proxy itself, so prefer the visitor IP headers.
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP'] as $h) {
        $v = trim($_SERVER[$h] ?? '');
        if ($v !== '' && filter_var($v, FILTER_VALIDATE_IP)) return substr($v, 0, 45);
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $first = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) return substr($first, 0, 45);
    }
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
}
function rate_count(string $action, int $minutes): int {
    $s = db()->prepare('SELECT COUNT(*) FROM rate_limits WHERE action = ? AND ip_address = ? AND created_at > (NOW() - make_interval(mins => CAST(? AS integer)))');
    $s->execute([$action, client_ip(), $minutes]);
    return (int)$s->fetchColumn();
}
function rate_hit(string $action): void {
    db()->prepare('INSERT INTO rate_limits (action, ip_address) VALUES (?, ?)')->execute([$action, client_ip()]);
    db()->exec('DELETE FROM rate_limits WHERE created_at < (NOW() - make_interval(days => 1))');
}

// ---------- Profile photo column ----------
// Job seekers can upload a profile photo (users.photo_path). If database/migrations/user_photo.sql was not run by hand,
// the column is added here the first time a logged-in person opens a page, so the site keeps working either way.
function ensure_photo_column(): void {
    if (!empty($_SESSION['photo_col_ok'])) return;
    try {
        $pdo = db();
        $has = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'users' AND column_name = 'photo_path'")->fetchColumn();
        if (!$has) $pdo->exec('ALTER TABLE public.users ADD COLUMN photo_path varchar(255)');
        $_SESSION['photo_col_ok'] = 1;
    } catch (Throwable $e) { error_log('photo_path column check failed: ' . $e->getMessage()); }
}
if (!empty($_SESSION['uid'])) ensure_photo_column();
