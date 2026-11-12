<?php
// Supabase Storage helper. Render's free plan wipes the server's disk on every deploy, so uploads are
// kept in Supabase Storage instead. If SUPABASE_URL / SUPABASE_SERVICE_KEY are NOT set (for example on XAMPP),
// the site falls back to saving files on the local disk like before.
require_once __DIR__ . '/config.php';

cfg_define('SUPABASE_URL', '');                 // https://YOURREF.supabase.co  (no trailing slash)
cfg_define('SUPABASE_SERVICE_KEY', '');         // SECRET. Server-side only. Never put it in JavaScript or GitHub.
cfg_define('STORAGE_PRIVATE_BUCKET', 'tbb-private'); // ID photos, resumes, payment proofs (PRIVATE bucket)
cfg_define('STORAGE_PUBLIC_BUCKET', 'tbb-public');   // company logos, payment QR codes (PUBLIC bucket)

function storage_on(): bool { return SUPABASE_URL !== '' && SUPABASE_SERVICE_KEY !== ''; }

function storage_mime(string $ext): string {
    return ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'][strtolower($ext)] ?? 'application/octet-stream';
}

// Low-level call to the Storage REST API. Returns [http_status, response_body].
function storage_call(string $method, string $bucket, string $key, ?string $body = null, ?string $ctype = null): array {
    $enc = implode('/', array_map('rawurlencode', explode('/', $key)));
    $url = rtrim(SUPABASE_URL, '/') . '/storage/v1/object/' . rawurlencode($bucket) . '/' . $enc;
    $headers = ['apikey: ' . SUPABASE_SERVICE_KEY];
    if (strpos(SUPABASE_SERVICE_KEY, 'eyJ') === 0) $headers[] = 'Authorization: Bearer ' . SUPABASE_SERVICE_KEY; // legacy JWT keys
    if ($ctype) $headers[] = 'Content-Type: ' . $ctype;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($resp === false) { error_log('Supabase Storage curl error: ' . curl_error($ch)); $code = 0; $resp = ''; }
    curl_close($ch);
    if ($code >= 400 || $code === 0) error_log("Supabase Storage $method $bucket/$key -> HTTP $code " . substr((string)$resp, 0, 300));
    return [$code, (string)$resp];
}

function storage_put(string $bucket, string $key, string $data, string $mime): bool {
    [$code] = storage_call('POST', $bucket, $key, $data, $mime);
    return $code >= 200 && $code < 300;
}
function storage_get(string $bucket, string $key): ?string {
    [$code, $body] = storage_call('GET', $bucket, $key);
    return $code === 200 ? $body : null;
}
function storage_delete(string $bucket, string $key): void {
    storage_call('DELETE', $bucket, $key);
}
