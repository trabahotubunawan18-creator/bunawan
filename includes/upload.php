<?php
// Safe upload helper. Private files are stored OUTSIDE htdocs (C:\myxampp\tbb_private\...),
// so nobody can open them by typing a URL. They are served only by PHP scripts that check permission.
// Where private files live. On XAMPP this is the same folder as before (next to htdocs).
// On a web host, set the PRIVATE_DIR environment variable to a writable folder (ideally on a persistent disk).
function private_base(): string {
    $env = getenv('PRIVATE_DIR');
    return ($env !== false && $env !== '') ? rtrim($env, '/\\') : dirname(__DIR__, 3) . '/tbb_private';
}
function private_dir(string $sub): string {
    $dir = private_base() . '/' . $sub;
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    return $dir;
}

// $allowed example: ['pdf' => ['application/pdf']]
// Returns [stored_path_or_null, error_or_null]. No file chosen returns [null, null].
function save_private_upload(array $f, array $allowed, int $maxBytes, string $sub): array {
    if (!isset($f['error']) || $f['error'] === UPLOAD_ERR_NO_FILE) return [null, null];
    if ($f['error'] !== UPLOAD_ERR_OK) return [null, 'The file could not be uploaded. Please try again.'];
    if ($f['size'] > $maxBytes) return [null, 'The file is too large. The maximum size is ' . round($maxBytes / 1048576) . ' MB.'];
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) return [null, 'Only ' . strtoupper(implode(' or ', array_keys($allowed))) . ' files are allowed.'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (!in_array($mime, $allowed[$ext], true)) return [null, 'That file does not look like a real ' . strtoupper($ext) . ' file.'];
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], private_dir($sub) . '/' . $name)) return [null, 'The file could not be saved.'];
    return [$sub . '/' . $name, null];
}

const RESUME_TYPES = [
    'pdf'  => ['application/pdf'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
];

// Documents and photos for employer verification (JPG, PNG, or PDF, up to 3 MB each)
const DOC_TYPES = [
    'pdf'  => ['application/pdf'],
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png'  => ['image/png'],
];
// Valid ID and selfie-with-ID: photos only (JPG or PNG), up to 3 MB each
const ID_TYPES = [
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png'  => ['image/png'],
];
function private_path(string $rel): string { return private_base() . '/' . $rel; }
function delete_private(?string $rel): void {
    if ($rel && preg_match('#^[a-z]+/[a-f0-9]{32}\.[a-z]{3,4}$#', $rel) && is_file(private_path($rel))) @unlink(private_path($rel));
}

// Public image upload (company logos). Verified with getimagesize, saved under uploads/<sub>/ with a random name.
function save_public_image(array $f, string $sub, int $maxBytes): array {
    if (!isset($f['error']) || $f['error'] === UPLOAD_ERR_NO_FILE) return [null, null];
    if ($f['error'] !== UPLOAD_ERR_OK) return [null, 'The image could not be uploaded. Please try again.'];
    if ($f['size'] > $maxBytes) return [null, 'The image is too large. The maximum size is ' . round($maxBytes / 1048576, 1) . ' MB.'];
    $info = @getimagesize($f['tmp_name']);
    $ext = $info ? [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png'][$info[2]] ?? null : null;
    if (!$ext) return [null, 'Please upload a JPG or PNG image.'];
    if ($info[0] > 4000 || $info[1] > 4000) return [null, 'The image is too large in pixels. Please use one under 4000 px.'];
    $dir = dirname(__DIR__) . '/uploads/' . $sub;
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) return [null, 'The image could not be saved.'];
    return [$sub . '/' . $name, null];
}
function delete_public(?string $rel): void {
    if ($rel && preg_match('#^logos/[a-f0-9]{32}\.(jpg|png)$#', $rel)) { $p = dirname(__DIR__) . '/uploads/' . $rel; if (is_file($p)) @unlink($p); }
}