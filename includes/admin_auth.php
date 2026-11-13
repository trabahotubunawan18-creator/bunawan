<?php
// Admin-only helpers: access check with a short idle timeout, and the activity log.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
const ADMIN_IDLE = 900; // 15 minutes

function require_admin(): void {
    if (!is_logged_in() || ($_SESSION['role'] ?? '') !== 'admin') redirect('admin/login.php');
    if (isset($_SESSION['admin_last']) && time() - $_SESSION['admin_last'] > ADMIN_IDLE) {
        session_unset(); session_destroy(); session_start();
        flash('info', 'You were logged out after 15 minutes of inactivity.');
        redirect('admin/login.php');
    }
    $_SESSION['admin_last'] = time();
    // The account must still exist, be an admin, and be active
    $s = db()->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'admin' AND status = 'active'");
    $s->execute([$_SESSION['uid']]);
    if (!$s->fetchColumn()) { session_unset(); session_destroy(); session_start(); redirect('admin/login.php'); }
}

function log_admin(string $action, ?string $targetType = null, ?int $targetId = null, ?string $reason = null): void {
    db()->prepare('INSERT INTO admin_activity_logs (admin_id, action, target_type, target_id, reason, ip_address) VALUES (?,?,?,?,?,?)')
        ->execute([$_SESSION['uid'], $action, $targetType, $targetId, $reason !== null ? mb_substr($reason, 0, 255) : null, client_ip()]);
}
function action_label(string $a): string { return ucfirst(str_replace('_', ' ', $a)); }

// A formal warning: the person is notified with the admin's reason, and it is logged.
// The activity log is also the warning history (action 'user_warned'), so no extra table is needed.
// The reason is cut to 220 characters so the whole text fits in the 255-character notification.
function warn_user(PDO $pdo, int $userId, string $reason, string $link): void {
    $reason = mb_substr(trim($reason), 0, 220);
    $pdo->prepare('INSERT INTO notifications (user_id, message, link) VALUES (?,?,?)')
        ->execute([$userId, 'Warning from the admin team: ' . $reason, $link]);
    log_admin('user_warned', 'user', $userId, $reason);
}
