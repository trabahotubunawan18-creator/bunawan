<?php
// Lets an employer open THEIR OWN verification files. Files live outside htdocs.
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/upload.php';
require_role('employer');
$cols = ['permit' => 'permit_doc_path', 'cert' => 'barangay_cert_path', 'photo' => 'workplace_photo_path', 'id' => 'id_doc_path', 'selfie' => 'selfie_path'];
$f = $_GET['f'] ?? '';
if (!isset($cols[$f])) { http_response_code(404); exit('Not found.'); }
$s = db()->prepare('SELECT ' . $cols[$f] . ' FROM employer_profiles WHERE user_id = ?');
$s->execute([$_SESSION['uid']]);
$rel = (string)$s->fetchColumn();
if (!preg_match('#^verification/[a-f0-9]{32}\.(pdf|jpg|jpeg|png)$#', $rel) || !is_file(private_path($rel))) { http_response_code(404); exit('File not found.'); }
$ext = pathinfo($rel, PATHINFO_EXTENSION);
$mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'][$ext];
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="document.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Length: ' . filesize(private_path($rel)));
readfile(private_path($rel));