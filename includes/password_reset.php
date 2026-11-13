<?php
// Helpers for password reset and email links.
// The password_resets table is created by database/schema.sql.
require_once __DIR__ . '/auth.php';

function reset_table(): void {
    // The password_resets table is created by database/schema.sql (Supabase / PostgreSQL).
}

// True ONLY when the visitor is on the same computer as the server (XAMPP on your own PC).
// This decides whether the reset link may be shown on screen, so it must never be true on a live host.
function is_local_request(): bool {
    // Render (and most hosts) always sit behind a proxy: never treat those requests as local.
    if (getenv('RENDER') !== false) return false;
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_PROTO', 'HTTP_FORWARDED',
              'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP'] as $h) {
        if (!empty($_SERVER[$h])) return false;
    }
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

// Full link for emails. On a live server, define('SITE_URL', 'https://yourdomain.com') in config.php.
function absolute_url(string $path): string {
    if (defined('SITE_URL')) return rtrim(SITE_URL, '/') . url($path);
    $scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . url($path);
}

// ---------- Sending email ----------
// If SMTP_HOST / SMTP_USER / SMTP_PASS are filled in config.php, mail goes out through that SMTP account
// (for example Gmail with an App Password). Otherwise PHP's built-in mail() is tried, which does not work on XAMPP.
function mail_last_error(?string $set = null): string {
    static $err = '';
    if ($set !== null) $err = $set;
    return $err;
}

function smtp_configured(): bool {
    return defined('SMTP_HOST') && SMTP_HOST !== '' && defined('SMTP_USER') && SMTP_USER !== '' && defined('SMTP_PASS') && SMTP_PASS !== '';
}

// Reads one (possibly multi-line) SMTP reply. Returns [code, text].
function smtp_read($fp): array {
    $text = ''; $code = 0;
    while (($line = fgets($fp, 1024)) !== false) {
        $text .= $line;
        $code = (int)substr($line, 0, 3);
        if (strlen($line) < 4 || $line[3] !== '-') break;
    }
    return [$code, trim($text)];
}

function smtp_cmd($fp, string $cmd, array $okCodes): string {
    if ($cmd !== '') fwrite($fp, $cmd . "\r\n");
    [$code, $text] = smtp_read($fp);
    if (!in_array($code, $okCodes, true)) {
        throw new RuntimeException($text !== '' ? 'Mail server said: ' . $text : 'The mail server did not answer (timeout).');
    }
    return $text;
}

function smtp_send(string $to, string $subject, string $body): void {
    $host   = SMTP_HOST;
    $port   = defined('SMTP_PORT') ? (int)SMTP_PORT : 587;
    $secure = defined('SMTP_SECURE') ? strtolower((string)SMTP_SECURE) : 'tls';   // 'tls' = STARTTLS (port 587), 'ssl' = port 465
    $verify = !defined('SMTP_VERIFY_TLS') || SMTP_VERIFY_TLS;
    $user   = SMTP_USER;
    $pass   = str_replace(' ', '', (string)SMTP_PASS);                            // Google shows App Passwords with spaces
    $from   = (defined('SMTP_FROM') && SMTP_FROM !== '') ? SMTP_FROM : $user;
    $name   = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Trabaho Tu Bunawan';

    $ctx = stream_context_create(['ssl' => [
        'verify_peer' => $verify, 'verify_peer_name' => $verify,
        'SNI_enabled' => true, 'peer_name' => $host,
    ]]);
    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) throw new RuntimeException("Could not connect to $host:$port ($errstr). Check the internet connection and the SMTP settings.");
    stream_set_timeout($fp, 20);

    try {
        smtp_cmd($fp, '', [220]);
        smtp_cmd($fp, 'EHLO localhost', [250]);
        if ($secure === 'tls') {
            smtp_cmd($fp, 'STARTTLS', [220]);
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Could not start a secure connection. On XAMPP, you can set SMTP_VERIFY_TLS to false in config.php.');
            }
            smtp_cmd($fp, 'EHLO localhost', [250]);
        }
        smtp_cmd($fp, 'AUTH LOGIN', [334]);
        smtp_cmd($fp, base64_encode($user), [334]);
        try {
            smtp_cmd($fp, base64_encode($pass), [235]);
        } catch (RuntimeException $ex) {
            throw new RuntimeException('The mail server refused the login. For Gmail, use a 16-letter App Password, not your normal password. (' . $ex->getMessage() . ')');
        }
        smtp_cmd($fp, 'MAIL FROM:<' . $from . '>', [250]);
        smtp_cmd($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
        smtp_cmd($fp, 'DATA', [354]);

        $host2 = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
        $head  = 'Date: ' . date('r') . "\r\n"
               . 'From: =?UTF-8?B?' . base64_encode($name) . '?= <' . $from . ">\r\n"
               . 'To: <' . $to . ">\r\n"
               . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n"
               . 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $host2 . ">\r\n"
               . "MIME-Version: 1.0\r\n"
               . "Content-Type: text/plain; charset=UTF-8\r\n"
               . "Content-Transfer-Encoding: base64\r\n";
        $msg = $head . "\r\n" . chunk_split(base64_encode($body), 76, "\r\n");
        fwrite($fp, $msg . ".\r\n");
        [$code, $text] = smtp_read($fp);
        if ($code !== 250) throw new RuntimeException('Mail server said: ' . $text);
        @fwrite($fp, "QUIT\r\n");
    } finally {
        fclose($fp);
    }
}

// Reads a setting from config.local.php (define) or from a Render environment variable.
function mail_setting(string $name): string {
    if (defined($name)) return (string)constant($name);
    $v = getenv($name);
    return $v === false ? '' : (string)$v;
}

// Sends through Brevo's HTTPS email API. Works on Render's free plan (it does not use SMTP ports).
// Needs BREVO_API_KEY and MAIL_FROM (an email address you verified as a Sender in Brevo).
function brevo_send(string $to, string $subject, string $body): void {
    if (!function_exists('curl_init')) throw new RuntimeException('PHP curl is not installed on this server.');
    $from = mail_setting('MAIL_FROM');
    if ($from === '') throw new RuntimeException('MAIL_FROM is not set. Set it to the sender email you verified in Brevo.');
    $name = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Trabaho Tu Bunawan';
    $payload = json_encode([
        'sender'      => ['name' => $name, 'email' => $from],
        'to'          => [['email' => $to]],
        'subject'     => $subject,
        'textContent' => $body,
    ]);
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['accept: application/json', 'content-type: application/json', 'api-key: ' . mail_setting('BREVO_API_KEY')],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $res  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($res === false) throw new RuntimeException('Could not reach the email service: ' . $err);
    if ($code < 200 || $code >= 300) throw new RuntimeException('Email service said (' . $code . '): ' . substr((string)$res, 0, 300));
}

function send_site_mail(string $to, string $subject, string $body): bool {
    $to      = str_replace(["\r", "\n", '<', '>'], '', $to);
    $subject = str_replace(["\r", "\n"], ' ', $subject);
    mail_last_error('');
    if (mail_setting('BREVO_API_KEY') !== '') {
        try {
            brevo_send($to, $subject, $body);
            return true;
        } catch (Throwable $ex) {
            mail_last_error($ex->getMessage());
            error_log('Email could not be sent: ' . $ex->getMessage());
            return false;
        }
    }
    if (smtp_configured()) {
        try {
            smtp_send($to, $subject, $body);
            return true;
        } catch (Throwable $ex) {
            mail_last_error($ex->getMessage());
            error_log('Email could not be sent: ' . $ex->getMessage());
            return false;
        }
    }
    $host = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    $headers = "From: Trabaho Tu Bunawan <no-reply@{$host}>\r\nContent-Type: text/plain; charset=UTF-8";
    $ok = @mail($to, $subject, $body, $headers);
    if (!$ok) mail_last_error('Email is not set up yet. Fill in the SMTP settings in includes/config.php.');
    return $ok;
}

// Returns the matching unused, unexpired reset row (id, user_id) for a raw token, or null.
function find_reset(string $token): ?array {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    reset_table();
    $s = db()->prepare('SELECT id, user_id FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()');
    $s->execute([hash('sha256', $token)]);
    return $s->fetch() ?: null;
}
