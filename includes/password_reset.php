<?php
// Helpers for password reset and email links.
// The password_resets table is created by database/schema.sql.
require_once __DIR__ . '/auth.php';

function reset_table(): void {
    // The password_resets table is created by database/schema.sql (Supabase / PostgreSQL).
}

// How long a reset link works, in minutes. Change it here and the email text follows.
function reset_minutes(): int {
    return 60;
}

// True when the visitor is on the same computer as the server (XAMPP on your own PC).
function is_local_request(): bool {
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

// Full link for emails. On a live server, set SITE_URL (for example https://yourdomain.com).
function absolute_url(string $path): string {
    if (defined('SITE_URL') && SITE_URL !== '') return rtrim(SITE_URL, '/') . url($path);
    $scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
    $host   = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/^[A-Za-z0-9.-]+(:\d+)?$/', $host)) $host = 'localhost';   // ignore a faked Host header
    return $scheme . '://' . $host . url($path);
}

// ---------- Sending email ----------
// Order of choice:
//   1. BREVO_API_KEY filled in  -> sent over HTTPS (works on Render, which blocks SMTP ports)
//   2. SMTP_USER / SMTP_PASS    -> sent through SMTP (for example Gmail with an App Password, fine on XAMPP)
//   3. otherwise                -> PHP's built-in mail(), which does not work on XAMPP
function mail_last_error(?string $set = null): string {
    static $err = '';
    if ($set !== null) $err = $set;
    return $err;
}

function smtp_configured(): bool {
    return defined('SMTP_HOST') && SMTP_HOST !== '' && defined('SMTP_USER') && SMTP_USER !== '' && defined('SMTP_PASS') && SMTP_PASS !== '';
}

function brevo_configured(): bool {
    return defined('BREVO_API_KEY') && BREVO_API_KEY !== '';
}

function mail_from_address(): string {
    if (defined('SMTP_FROM') && SMTP_FROM !== '') return SMTP_FROM;
    return defined('SMTP_USER') ? (string)SMTP_USER : '';
}

function mail_from_name(): string {
    return defined('SMTP_FROM_NAME') ? (string)SMTP_FROM_NAME : 'Trabaho Tu Bunawan';
}

// Builds the message body. With $html it becomes "multipart/alternative": mail apps show the nice HTML version,
// and fall back to the plain text one if they cannot. Returns [Content-Type header lines, encoded body].
function mail_mime(string $text, ?string $html): array {
    $enc = function (string $s): string { return chunk_split(base64_encode($s), 76, "\r\n"); };
    if ($html === null || $html === '') {
        return ["Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64", $enc($text)];
    }
    $b = 'tbb_' . bin2hex(random_bytes(12));
    $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $enc($text)
          . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $enc($html)
          . "--$b--\r\n";
    return ["Content-Type: multipart/alternative; boundary=\"$b\"", $body];
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

function smtp_send(string $to, string $subject, string $body, ?string $html = null): void {
    $host   = SMTP_HOST;
    $port   = defined('SMTP_PORT') ? (int)SMTP_PORT : 587;
    $secure = defined('SMTP_SECURE') ? strtolower((string)SMTP_SECURE) : 'tls';   // 'tls' = STARTTLS (port 587), 'ssl' = port 465
    $verify = !defined('SMTP_VERIFY_TLS') || SMTP_VERIFY_TLS;
    $user   = SMTP_USER;
    $pass   = str_replace(' ', '', (string)SMTP_PASS);                            // Google shows App Passwords with spaces
    $from   = mail_from_address();
    $name   = mail_from_name();

    $ctx = stream_context_create(['ssl' => [
        'verify_peer' => $verify, 'verify_peer_name' => $verify,
        'SNI_enabled' => true, 'peer_name' => $host,
    ]]);
    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) throw new RuntimeException("Could not connect to $host:$port ($errstr). Check the internet connection and the SMTP settings. (Render's free plan blocks SMTP: use BREVO_API_KEY there.)");
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
        [$ctype, $mbody] = mail_mime($body, $html);
        $head  = 'Date: ' . date('r') . "\r\n"
               . 'From: =?UTF-8?B?' . base64_encode($name) . '?= <' . $from . ">\r\n"
               . 'To: <' . $to . ">\r\n"
               . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n"
               . 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $host2 . ">\r\n"
               . "Auto-Submitted: auto-generated\r\n"
               . "MIME-Version: 1.0\r\n"
               . $ctype . "\r\n";
        fwrite($fp, $head . "\r\n" . $mbody . ".\r\n");
        [$code, $text] = smtp_read($fp);
        if ($code !== 250) throw new RuntimeException('Mail server said: ' . $text);
        @fwrite($fp, "QUIT\r\n");
    } finally {
        fclose($fp);
    }
}

// Sends through Brevo's HTTPS API (https://www.brevo.com, free plan: 300 emails a day).
// The sender address must be verified in Brevo first (Settings -> Senders, Domains & IPs).
function brevo_send(string $to, string $toName, string $subject, string $text, ?string $html): void {
    $from = mail_from_address();
    if ($from === '') throw new RuntimeException('No sender address. Set SMTP_FROM (or SMTP_USER) to the sender you verified in Brevo.');
    $rcpt = ['email' => $to];
    if ($toName !== '') $rcpt['name'] = $toName;
    $payload = [
        'sender'      => ['name' => mail_from_name(), 'email' => $from],
        'to'          => [$rcpt],
        'subject'     => $subject,
        'textContent' => $text,
    ];
    if ($html !== null && $html !== '') $payload['htmlContent'] = $html;
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $headers = ['accept: application/json', 'content-type: application/json', 'api-key: ' . BREVO_API_KEY];
    $endpoint = 'https://api.brevo.com/v3/smtp/email';

    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        if ($resp === false) throw new RuntimeException('Could not reach Brevo (' . $cerr . ').');
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $json,
            'timeout' => 20, 'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents($endpoint, false, $ctx);
        if ($resp === false) throw new RuntimeException('Could not reach Brevo. Check the internet connection.');
        $code = 0;
        if (!empty($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) $code = (int)$m[1];
    }
    if ($code < 200 || $code >= 300) {
        $j = json_decode((string)$resp, true);
        $why = is_array($j) && !empty($j['message']) ? $j['message'] : substr((string)$resp, 0, 200);
        throw new RuntimeException("Brevo refused the email (HTTP $code): $why");
    }
}

function send_site_mail(string $to, string $subject, string $body, ?string $html = null, string $toName = ''): bool {
    $to      = str_replace(["\r", "\n", '<', '>'], '', $to);
    $subject = str_replace(["\r", "\n"], ' ', $subject);
    $toName  = str_replace(["\r", "\n", '<', '>', '"'], '', $toName);
    mail_last_error('');
    if (brevo_configured()) {
        try {
            brevo_send($to, $toName, $subject, $body, $html);
            return true;
        } catch (Throwable $ex) {
            mail_last_error($ex->getMessage());
            error_log('Email could not be sent (Brevo): ' . $ex->getMessage());
            return false;
        }
    }
    if (smtp_configured()) {
        try {
            smtp_send($to, $subject, $body, $html);
            return true;
        } catch (Throwable $ex) {
            mail_last_error($ex->getMessage());
            error_log('Email could not be sent: ' . $ex->getMessage());
            return false;
        }
    }
    $host = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    [$ctype, $mbody] = mail_mime($body, $html);
    $headers = "From: Trabaho Tu Bunawan <no-reply@{$host}>\r\nMIME-Version: 1.0\r\n" . $ctype;
    $ok = @mail($to, $subject, $mbody, $headers);
    if (!$ok) mail_last_error('Email is not set up yet. Fill in the email settings in includes/config.local.php.');
    return $ok;
}

// ---------- Email design ----------
// One branded layout used by every email, so they all look the same and stay organised.
// Uses tables and inline styles because that is what Gmail, Outlook and phone mail apps understand.
function mail_layout(string $title, string $preheader, string $content): string {
    $F       = 'Arial,Helvetica,sans-serif';
    $home    = absolute_url('index.php');
    $contact = absolute_url('contact.php');
    $year    = date('Y');
    ob_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
</head>
<body style="margin:0;padding:0;background-color:#F3F6F4;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#F3F6F4;font-size:1px;line-height:1px;"><?= e($preheader) ?></div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#F3F6F4;">
<tr><td align="center" style="padding:24px 12px;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;">
    <tr><td align="center" bgcolor="#0F3D2E" style="background-color:#0F3D2E;padding:26px 24px 22px;border-radius:12px 12px 0 0;border-bottom:4px solid #F2B705;">
      <a href="<?= e($home) ?>" target="_blank" style="font-family:<?= $F ?>;font-size:24px;font-weight:bold;color:#FFFFFF;text-decoration:none;letter-spacing:0.3px;">Trabaho Tu Bunawan</a>
    </td></tr>
    <tr><td bgcolor="#FFFFFF" style="background-color:#FFFFFF;padding:32px 30px 28px;font-family:<?= $F ?>;color:#12281E;font-size:16px;line-height:1.6;border-left:1px solid #E1E9E4;border-right:1px solid #E1E9E4;">
<?= $content ?>
    </td></tr>
    <tr><td bgcolor="#FFFFFF" style="background-color:#FFFFFF;padding:0 30px 2px;border-left:1px solid #E1E9E4;border-right:1px solid #E1E9E4;border-bottom:1px solid #E1E9E4;border-radius:0 0 12px 12px;">&nbsp;</td></tr>
    <tr><td align="center" style="padding:20px 16px 4px;font-family:<?= $F ?>;font-size:13px;line-height:1.6;color:#52665A;">
      This is an automated message from Trabaho Tu Bunawan. Please do not reply to this email.<br>
      Need help? <a href="<?= e($contact) ?>" target="_blank" style="color:#1B7F45;text-decoration:underline;">Contact us</a>
    </td></tr>
    <tr><td align="center" style="padding:4px 16px 0;font-family:<?= $F ?>;font-size:12px;color:#7C8E83;">&copy; <?= e($year) ?> Trabaho Tu Bunawan</td></tr>
  </table>
</td></tr>
</table>
</body>
</html>
<?php
    return (string)ob_get_clean();
}

// The "reset your password" email. Returns ['subject' => ..., 'text' => ..., 'html' => ...].
function build_reset_email(string $name, string $email, string $link): array {
    $F       = 'Arial,Helvetica,sans-serif';
    $mins    = reset_minutes();
    $when    = date('F j, Y, g:i A') . ' (Philippine time)';
    $ip      = client_ip();
    $contact = absolute_url('contact.php');

    ob_start(); ?>
      <h1 style="margin:0 0 18px;font-family:<?= $F ?>;font-size:24px;line-height:1.3;color:#12281E;">Reset your password</h1>
      <p style="margin:0 0 14px;">Hello <b><?= e($name) ?></b>,</p>
      <p style="margin:0 0 14px;">We received a request to reset the password of the Trabaho Tu Bunawan account registered with <b><?= e($email) ?></b>. Click the button below to choose a new password.</p>

      <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:26px auto;">
        <tr><td align="center" bgcolor="#1B7F45" style="background-color:#1B7F45;border-radius:8px;">
          <a href="<?= e($link) ?>" target="_blank" style="display:inline-block;padding:15px 34px;font-family:<?= $F ?>;font-size:16px;font-weight:bold;color:#FFFFFF;text-decoration:none;border-radius:8px;">Reset my password</a>
        </td></tr>
      </table>

      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 18px;">
        <tr><td bgcolor="#EEF7F1" style="background-color:#EEF7F1;border-left:4px solid #1B7F45;padding:12px 16px;font-family:<?= $F ?>;font-size:14px;line-height:1.6;color:#12281E;">
          <b>Important:</b> this link expires in <b><?= e($mins) ?> minutes</b> and can be used <b>only once</b>.
        </td></tr>
      </table>

      <p style="margin:0 0 6px;font-size:14px;color:#52665A;">Button not working? Copy this link and paste it into your browser:</p>
      <p style="margin:0 0 22px;font-size:13px;line-height:1.5;word-break:break-all;"><a href="<?= e($link) ?>" target="_blank" style="color:#1B7F45;text-decoration:underline;"><?= e($link) ?></a></p>

      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px;">
        <tr><td bgcolor="#FFF8E1" style="background-color:#FFF8E1;border-left:4px solid #F2B705;padding:12px 16px;font-family:<?= $F ?>;font-size:14px;line-height:1.6;color:#12281E;">
          <b>Did not ask for this?</b> You can safely ignore this email. Your password will not change unless you open the link above and choose a new one.
        </td></tr>
      </table>

      <p style="margin:0 0 6px;font-size:14px;font-weight:bold;color:#12281E;">Request details</p>
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px;font-family:<?= $F ?>;font-size:14px;line-height:1.6;color:#52665A;">
        <tr><td style="padding:2px 16px 2px 0;">Date and time</td><td style="padding:2px 0;color:#12281E;"><?= e($when) ?></td></tr>
        <tr><td style="padding:2px 16px 2px 0;">IP address</td><td style="padding:2px 0;color:#12281E;"><?= e($ip) ?></td></tr>
      </table>

      <p style="margin:0;font-size:13px;line-height:1.6;color:#52665A;border-top:1px solid #E1E9E4;padding-top:16px;"><b>Stay safe:</b> never share this link with anyone. Trabaho Tu Bunawan staff will never ask for your password.</p>
<?php
    $content = (string)ob_get_clean();

    $text = "Hello {$name},\n\n"
          . "We received a request to reset the password of the Trabaho Tu Bunawan account registered with {$email}.\n\n"
          . "RESET YOUR PASSWORD\n"
          . "Open this link to choose a new password:\n{$link}\n\n"
          . "IMPORTANT\n"
          . "- This link expires in {$mins} minutes.\n"
          . "- It can be used only once.\n\n"
          . "DID NOT ASK FOR THIS?\n"
          . "You can safely ignore this email. Your password will not change unless you open the link above and choose a new one.\n\n"
          . "REQUEST DETAILS\n"
          . "Date and time: {$when}\n"
          . "IP address: {$ip}\n\n"
          . "Stay safe: never share this link with anyone. Trabaho Tu Bunawan staff will never ask for your password.\n\n"
          . "--\nTrabaho Tu Bunawan\n"
          . "This is an automated message. Please do not reply to this email.\n"
          . "Need help? {$contact}\n";

    return [
        'subject' => 'Reset your Trabaho Tu Bunawan password',
        'text'    => $text,
        'html'    => mail_layout('Reset your password', "Use this link within {$mins} minutes to choose a new Trabaho Tu Bunawan password.", $content),
    ];
}

// The "your password was changed" confirmation, sent after a successful reset.
function build_password_changed_email(string $name, string $email): array {
    $F       = 'Arial,Helvetica,sans-serif';
    $when    = date('F j, Y, g:i A') . ' (Philippine time)';
    $ip      = client_ip();
    $login   = absolute_url('login.php');
    $forgot  = absolute_url('forgot-password.php');
    $contact = absolute_url('contact.php');

    ob_start(); ?>
      <h1 style="margin:0 0 18px;font-family:<?= $F ?>;font-size:24px;line-height:1.3;color:#12281E;">Your password was changed</h1>
      <p style="margin:0 0 14px;">Hello <b><?= e($name) ?></b>,</p>
      <p style="margin:0 0 14px;">This is a confirmation that the password of your Trabaho Tu Bunawan account (<b><?= e($email) ?></b>) was changed successfully. You can now log in with your new password.</p>

      <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:26px auto;">
        <tr><td align="center" bgcolor="#1B7F45" style="background-color:#1B7F45;border-radius:8px;">
          <a href="<?= e($login) ?>" target="_blank" style="display:inline-block;padding:15px 34px;font-family:<?= $F ?>;font-size:16px;font-weight:bold;color:#FFFFFF;text-decoration:none;border-radius:8px;">Log in</a>
        </td></tr>
      </table>

      <p style="margin:0 0 6px;font-size:14px;font-weight:bold;color:#12281E;">Change details</p>
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px;font-family:<?= $F ?>;font-size:14px;line-height:1.6;color:#52665A;">
        <tr><td style="padding:2px 16px 2px 0;">Date and time</td><td style="padding:2px 0;color:#12281E;"><?= e($when) ?></td></tr>
        <tr><td style="padding:2px 16px 2px 0;">IP address</td><td style="padding:2px 0;color:#12281E;"><?= e($ip) ?></td></tr>
      </table>

      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0;">
        <tr><td bgcolor="#FFF8E1" style="background-color:#FFF8E1;border-left:4px solid #F2B705;padding:12px 16px;font-family:<?= $F ?>;font-size:14px;line-height:1.6;color:#12281E;">
          <b>Was this not you?</b> Someone else may have access to your email. <a href="<?= e($forgot) ?>" target="_blank" style="color:#1B7F45;text-decoration:underline;">Reset your password again</a> right away and <a href="<?= e($contact) ?>" target="_blank" style="color:#1B7F45;text-decoration:underline;">contact us</a>.
        </td></tr>
      </table>
<?php
    $content = (string)ob_get_clean();

    $text = "Hello {$name},\n\n"
          . "This is a confirmation that the password of your Trabaho Tu Bunawan account ({$email}) was changed successfully.\n"
          . "You can now log in with your new password:\n{$login}\n\n"
          . "CHANGE DETAILS\n"
          . "Date and time: {$when}\n"
          . "IP address: {$ip}\n\n"
          . "WAS THIS NOT YOU?\n"
          . "Someone else may have access to your email. Reset your password again right away:\n{$forgot}\n"
          . "and contact us: {$contact}\n\n"
          . "--\nTrabaho Tu Bunawan\n"
          . "This is an automated message. Please do not reply to this email.\n";

    return [
        'subject' => 'Your Trabaho Tu Bunawan password was changed',
        'text'    => $text,
        'html'    => mail_layout('Your password was changed', 'Your Trabaho Tu Bunawan password was changed successfully.', $content),
    ];
}

// Returns the matching unused, unexpired reset row (id, user_id) for a raw token, or null.
function find_reset(string $token): ?array {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    reset_table();
    $s = db()->prepare('SELECT id, user_id FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()');
    $s->execute([hash('sha256', $token)]);
    return $s->fetch() ?: null;
}
