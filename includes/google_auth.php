<?php
// "Continue with Google" helpers (OAuth 2.0 login). Used by login.php, register.php,
// google-start.php and google-callback.php.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/password_reset.php';   // for absolute_url()

// Google sign-in only shows up once both values are set (in config.local.php or as Render environment variables).
function google_enabled(): bool {
    return defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID !== '' && defined('GOOGLE_CLIENT_SECRET') && GOOGLE_CLIENT_SECRET !== '';
}

// The address Google sends people back to. It must be added in Google Cloud exactly as shown here.
function google_redirect_uri(): string {
    return absolute_url('google-callback.php');
}

// $mode: 'login' (existing accounts), 'register' (new job seekers) or 'employer' (start of employer registration).
function google_button(string $mode, string $label = 'Continue with Google'): string {
    if (!google_enabled()) return '';
    $g = '<svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>';
    return '<a class="btn btn-outline block" href="' . e(url('google-start.php?mode=' . urlencode($mode))) . '" '
         . 'style="display:flex;align-items:center;justify-content:center;gap:10px;font-weight:600;">' . $g . '<span>' . e($label) . '</span></a>'
         . '<div style="display:flex;align-items:center;gap:12px;margin:16px 0;color:#7C8E83;font-size:13px;">'
         . '<span style="flex:1;height:1px;background:#E1E9E4;"></span><span>or</span><span style="flex:1;height:1px;background:#E1E9E4;"></span></div>';
}

// Small helper for talking to Google over HTTPS. Returns the decoded JSON, or null if anything went wrong.
function google_http(string $url, ?array $post = null, ?string $bearer = null): ?array {
    $headers = ['Accept: application/json'];
    if ($bearer !== null) $headers[] = 'Authorization: Bearer ' . $bearer;
    $noVerify = defined('SMTP_VERIFY_TLS') && !SMTP_VERIFY_TLS;   // same switch as email: only for XAMPP without a CA bundle
    $resp = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $headers];
        if ($post !== null) { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = http_build_query($post); }
        if ($noVerify) { $opts[CURLOPT_SSL_VERIFYPEER] = false; $opts[CURLOPT_SSL_VERIFYHOST] = 0; }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        if ($resp === false) error_log('Google request failed: ' . curl_error($ch));
        curl_close($ch);
    } else {
        if ($post !== null) $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $http = ['method' => $post !== null ? 'POST' : 'GET', 'timeout' => 20, 'ignore_errors' => true, 'header' => implode("\r\n", $headers)];
        if ($post !== null) $http['content'] = http_build_query($post);
        $ssl = $noVerify ? ['verify_peer' => false, 'verify_peer_name' => false] : [];
        $resp = @file_get_contents($url, false, stream_context_create(['http' => $http, 'ssl' => $ssl]));
    }
    if ($resp === false) return null;
    $j = json_decode((string)$resp, true);
    return is_array($j) ? $j : null;
}
