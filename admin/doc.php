<?php
// Admin-only viewer for employer verification files. Files live in Supabase Storage (or outside htdocs on XAMPP).
require __DIR__ . '/../includes/admin_auth.php';
require __DIR__ . '/../includes/upload.php';
require_admin();
$cols = ['permit' => 'permit_doc_path', 'cert' => 'barangay_cert_path', 'photo' => 'workplace_photo_path', 'id' => 'id_doc_path', 'selfie' => 'selfie_path'];
$f = $_GET['f'] ?? '';
if (!isset($cols[$f])) { http_response_code(404); exit('Not found.'); }
$s = db()->prepare('SELECT ' . $cols[$f] . ' FROM employer_profiles WHERE user_id = ?');
$s->execute([(int)($_GET['id'] ?? 0)]);
$rel = (string)$s->fetchColumn();
if (!preg_match('#^verification/[a-f0-9]{32}\.(pdf|jpg|jpeg|png)$#', $rel) || ($data = private_read($rel)) === null) { http_response_code(404); exit('File not found.'); }
$ext = pathinfo($rel, PATHINFO_EXTENSION);
header('Content-Type: ' . ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'][$ext]);
header('Content-Disposition: inline; filename="document.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Length: ' . strlen($data));
echo $data;
