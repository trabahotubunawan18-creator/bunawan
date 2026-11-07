<?php
// Admin-only viewer for a payment screenshot. Files live outside htdocs.
require __DIR__ . '/../includes/admin_auth.php';
require __DIR__ . '/../includes/upload.php';
require_admin();
$s = db()->prepare('SELECT proof_path FROM premium_subscriptions WHERE id = ?');
$s->execute([(int)($_GET['id'] ?? 0)]);
$rel = (string)$s->fetchColumn();
if (!preg_match('#^payments/[a-f0-9]{32}\.(jpg|jpeg|png)$#', $rel) || !is_file(private_path($rel))) { http_response_code(404); exit('File not found.'); }
$ext = pathinfo($rel, PATHINFO_EXTENSION);
header('Content-Type: ' . ($ext === 'png' ? 'image/png' : 'image/jpeg'));
header('Content-Disposition: inline; filename="payment-proof.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Length: ' . filesize(private_path($rel)));
readfile(private_path($rel));