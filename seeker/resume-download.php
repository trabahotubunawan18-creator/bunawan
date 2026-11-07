<?php
// Lets a job seeker download their OWN resume. Files live outside htdocs.
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/upload.php';
require_role('seeker');
$s = db()->prepare('SELECT resume_path FROM seeker_profiles WHERE user_id = ?');
$s->execute([$_SESSION['uid']]);
$rel = (string)$s->fetchColumn();
if (!preg_match('#^resumes/[a-f0-9]{32}\.(pdf|docx)$#', $rel) || !is_file(private_path($rel))) { http_response_code(404); exit('No resume found.'); }
$ext = pathinfo($rel, PATHINFO_EXTENSION);
header('Content-Type: ' . ($ext === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
header('Content-Disposition: attachment; filename="my-resume.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize(private_path($rel)));
readfile(private_path($rel));