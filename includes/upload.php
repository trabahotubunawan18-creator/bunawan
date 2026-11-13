<?php
require_once __DIR__ . '/storage.php';
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
    if (storage_on()) {
        if (!storage_put(STORAGE_PRIVATE_BUCKET, $sub . '/' . $name, (string)file_get_contents($f['tmp_name']), storage_mime($ext))) return [null, 'The file could not be saved.'];
    } elseif (!move_uploaded_file($f['tmp_name'], private_dir($sub) . '/' . $name)) return [null, 'The file could not be saved.'];
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
    if (!$rel || !preg_match('#^[a-z]+/[a-f0-9]{32}\.[a-z]{3,4}$#', $rel)) return;
    if (storage_on()) { storage_delete(STORAGE_PRIVATE_BUCKET, $rel); return; }
    if (is_file(private_path($rel))) @unlink(private_path($rel));
}
// Returns the file's contents, or null if it does not exist. Used by the permission-checked download pages.
function private_read(string $rel): ?string {
    if (storage_on()) return storage_get(STORAGE_PRIVATE_BUCKET, $rel);
    return is_file(private_path($rel)) ? (string)file_get_contents(private_path($rel)) : null;
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
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (storage_on()) {
        if (!storage_put(STORAGE_PUBLIC_BUCKET, $sub . '/' . $name, (string)file_get_contents($f['tmp_name']), storage_mime($ext))) return [null, 'The image could not be saved.'];
        return [$sub . '/' . $name, null];
    }
    $dir = dirname(__DIR__) . '/uploads/' . $sub;
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) return [null, 'The image could not be saved.'];
    return [$sub . '/' . $name, null];
}
function delete_public(?string $rel): void {
    if (!$rel || !preg_match('#^(logos|qr|avatars)/[a-f0-9]{32}\.(jpg|png)$#', $rel)) return;
    if (storage_on()) { storage_delete(STORAGE_PUBLIC_BUCKET, $rel); return; }
    $p = dirname(__DIR__) . '/uploads/' . $rel; if (is_file($p)) @unlink($p);
}

// Profile photo (job seekers). Saved in the public area under avatars/ so it can be shown on any page.
// When the GD extension is installed (it is in the Dockerfile) the picture is also turned into a 400 px square JPEG:
// pages stay fast even with big phone photos, and the GPS / camera details phones hide inside photos are removed.
// Without GD it falls back to the same rules as company logos (JPG or PNG, up to 1 MB).
function save_profile_photo(array $f, string $sub = 'avatars'): array {
    if (!isset($f['error']) || $f['error'] === UPLOAD_ERR_NO_FILE) return [null, null];
    $gd = function_exists('imagecreatefromjpeg') && function_exists('imagecreatefrompng') && function_exists('imagejpeg');
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) return [null, 'The photo is too large. Please use one under ' . ($gd ? 5 : 1) . ' MB.'];
    if (!$gd) return save_public_image($f, $sub, 1048576);
    if ($f['error'] !== UPLOAD_ERR_OK) return [null, 'The photo could not be uploaded. Please try again.'];
    if ($f['size'] > 5 * 1048576) return [null, 'The photo is too large. Please use one under 5 MB.'];
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) return [null, 'Please upload a JPG or PNG photo.'];
    if ($info[0] * $info[1] > 16000000) return [null, 'The photo is too large in pixels. Please use a smaller one.'];
    $src = $info[2] === IMAGETYPE_JPEG ? @imagecreatefromjpeg($f['tmp_name']) : @imagecreatefrompng($f['tmp_name']);
    if (!$src) return [null, 'That photo could not be read. Please try another one.'];
    // Phones save a sideways picture plus a "rotate me" note. Apply the note, since the re-saved copy has no notes.
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $ex = @exif_read_data($f['tmp_name']);
        $rot = [3 => 180, 6 => -90, 8 => 90][is_array($ex) ? ($ex['Orientation'] ?? 1) : 1] ?? 0;
        if ($rot) { $r = imagerotate($src, $rot, 0); if ($r) { imagedestroy($src); $src = $r; } }
    }
    $w = imagesx($src); $h = imagesy($src); $side = min($w, $h);
    $out = imagecreatetruecolor(400, 400);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));   // transparent PNG areas become white
    imagecopyresampled($out, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), 400, 400, $side, $side);
    ob_start(); imagejpeg($out, null, 85); $jpg = (string)ob_get_clean();
    imagedestroy($src); imagedestroy($out);
    if ($jpg === '') return [null, 'The photo could not be saved.'];
    $name = bin2hex(random_bytes(16)) . '.jpg';
    if (storage_on()) {
        if (!storage_put(STORAGE_PUBLIC_BUCKET, $sub . '/' . $name, $jpg, 'image/jpeg')) return [null, 'The photo could not be saved.'];
        return [$sub . '/' . $name, null];
    }
    $dir = dirname(__DIR__) . '/uploads/' . $sub;
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    if (file_put_contents($dir . '/' . $name, $jpg) === false) return [null, 'The photo could not be saved.'];
    return [$sub . '/' . $name, null];
}
