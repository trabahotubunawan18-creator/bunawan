<?php
// Lets an employer download the resume of someone who applied to ONE OF THEIR jobs. Files live in Supabase Storage (or outside htdocs on XAMPP).
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/upload.php';
require_role('employer');
$s = db()->prepare('SELECT a.resume_path, a.applicant_name FROM job_applications a JOIN job_posts j ON j.id = a.job_id WHERE a.id = ? AND j.employer_id = ?');
$s->execute([(int)($_GET['id'] ?? 0), $_SESSION['uid']]);
$row = $s->fetch();
$rel = (string)($row['resume_path'] ?? '');
if (!preg_match('#^resumes/[a-f0-9]{32}\.(pdf|docx)$#', $rel) || ($data = private_read($rel)) === null) { http_response_code(404); exit('No resume found.'); }
$ext = pathinfo($rel, PATHINFO_EXTENSION);
$name = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $row['applicant_name']), '-') ?: 'applicant';
header('Content-Type: ' . ($ext === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
header('Content-Disposition: attachment; filename="resume-' . $name . '.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . strlen($data));
echo $data;
