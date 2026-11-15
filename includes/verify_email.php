<?php
// Email verification: NO account exists until the person opens the "confirm your email" link.
// A sign-up waits in the pending_registrations table (see database/migrations/pending_registrations.sql).
// Opening the link creates the real account (users + profile) and deletes the waiting row.
// A waiting row that is never confirmed is deleted automatically after PENDING_HOURS, together with any uploaded files.
// Uses the same email sender and layout as the password reset (includes/password_reset.php).
require_once __DIR__ . '/password_reset.php';
require_once __DIR__ . '/upload.php';

cfg_define('PENDING_HOURS', 24);   // how long the verification link works

// The "confirm your email" message. $role is 'seeker' or 'employer'.
function build_verify_email(string $name, string $email, string $link, string $role = 'seeker'): array {
    $F       = 'Arial,Helvetica,sans-serif';
    $contact = absolute_url('contact.php');
    $next    = $role === 'employer'
        ? 'After you confirm your email, your employer account is created and our admin team will review your application. You can post jobs once it is approved.'
        : 'After you confirm your email, your account is created and you can log in and start looking for jobs near you.';

    ob_start(); ?>
      <h1 style="margin:0 0 18px;font-family:<?= $F ?>;font-size:24px;line-height:1.3;color:#12281E;">Confirm your email address</h1>
      <p style="margin:0 0 14px;">Hello <b><?= e($name) ?></b>,</p>
      <p style="margin:0 0 14px;">Thank you for signing up for Trabaho Tu Bunawan with <b><?= e($email) ?></b>. Please confirm that this email address is yours by clicking the button below. Your account is created once you do. The link works for <?= (int)PENDING_HOURS ?> hours.</p>

      <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:26px auto;">
        <tr><td align="center" bgcolor="#1B7F45" style="background-color:#1B7F45;border-radius:8px;">
          <a href="<?= e($link) ?>" target="_blank" style="display:inline-block;padding:15px 34px;font-family:<?= $F ?>;font-size:16px;font-weight:bold;color:#FFFFFF;text-decoration:none;border-radius:8px;">Confirm my email</a>
        </td></tr>
      </table>

      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 18px;">
        <tr><td bgcolor="#EEF7F1" style="background-color:#EEF7F1;border-left:4px solid #1B7F45;padding:12px 16px;font-family:<?= $F ?>;font-size:14px;line-height:1.6;color:#12281E;">
          <b>What happens next:</b> <?= e($next) ?>
        </td></tr>
      </table>

      <p style="margin:0 0 6px;font-size:14px;color:#52665A;">Button not working? Copy this link and paste it into your browser:</p>
      <p style="margin:0 0 22px;font-size:13px;line-height:1.5;word-break:break-all;"><a href="<?= e($link) ?>" target="_blank" style="color:#1B7F45;text-decoration:underline;"><?= e($link) ?></a></p>

      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px;">
        <tr><td bgcolor="#FFF8E1" style="background-color:#FFF8E1;border-left:4px solid #F2B705;padding:12px 16px;font-family:<?= $F ?>;font-size:14px;line-height:1.6;color:#12281E;">
          <b>Did not sign up?</b> You can safely ignore this email. No account is created unless this link is opened.
        </td></tr>
      </table>

      <p style="margin:0;font-size:13px;line-height:1.6;color:#52665A;border-top:1px solid #E1E9E4;padding-top:16px;"><b>Stay safe:</b> never share this link with anyone. Trabaho Tu Bunawan staff will never ask for your password.</p>
<?php
    $content = (string)ob_get_clean();

    $text = "Hello {$name},\n\n"
          . "Thank you for signing up for Trabaho Tu Bunawan with {$email}.\n"
          . "Please confirm that this email address is yours.\n\n"
          . "CONFIRM YOUR EMAIL\n"
          . "Open this link:\n{$link}\n\n"
          . "WHAT HAPPENS NEXT\n{$next}\n\n"
          . "DID NOT SIGN UP?\n"
          . "You can safely ignore this email. No account is created unless this link is opened.\n\n"
          . "Stay safe: never share this link with anyone. Trabaho Tu Bunawan staff will never ask for your password.\n\n"
          . "--\nTrabaho Tu Bunawan\n"
          . "This is an automated message. Please do not reply to this email.\n"
          . "Need help? {$contact}\n";

    return [
        'subject' => 'Confirm your email for Trabaho Tu Bunawan',
        'text'    => $text,
        'html'    => mail_layout('Confirm your email address', 'One click to confirm your email and activate your Trabaho Tu Bunawan account.', $content),
    ];
}

// ---------------------------------------------------------------------------------------------
// Waiting sign-ups (pending_registrations)
// ---------------------------------------------------------------------------------------------

// Every uploaded file path saved with a waiting employer application.
function pending_files(array $payload): array {
    $out = [];
    foreach (($payload['paths'] ?? []) as $p) if (is_string($p) && $p !== '') $out[] = $p;
    return $out;
}
function pending_drop_files(array $payload): void {
    foreach (pending_files($payload) as $f) delete_private($f);
}

// Deletes waiting sign-ups whose link has expired, together with the files they uploaded.
// Runs by itself whenever someone signs up, resends, or opens a link, so no cron job is needed.
function purge_expired_registrations(): void {
    try {
        $pdo  = db();
        $rows = $pdo->query('SELECT id, payload FROM pending_registrations WHERE expires_at < NOW() LIMIT 100')->fetchAll();
        foreach ($rows as $r) {
            $del = $pdo->prepare('DELETE FROM pending_registrations WHERE id = ?');
            $del->execute([$r['id']]);
            // Only the request that really removed the row deletes the files.
            if ($del->rowCount() === 1) pending_drop_files(json_decode((string)$r['payload'], true) ?: []);
        }
    } catch (Throwable $e) { error_log('purge_expired_registrations failed: ' . $e->getMessage()); }
}

// Emails the verification link. Returns ['sent' => bool, 'dev_link' => string|null].
// dev_link is only filled in on your own computer (XAMPP) when the email could not be sent.
function send_verification_link(string $token, string $name, string $email, string $role): array {
    $link = absolute_url('verify-email.php?token=' . $token);
    $mail = build_verify_email($name, $email, $link, $role);
    $ok   = send_site_mail($email, $mail['subject'], $mail['text'], $mail['html'], $name);
    return ['sent' => $ok, 'dev_link' => (!$ok && is_local_request()) ? $link : null];
}

// Saves a sign-up as WAITING (no account yet) and emails the link. A new sign-up with the same email replaces the old
// waiting one (and its uploaded files). $payload for a seeker: ['phone' => ..., 'password_hash' => ...].
// $payload for an employer: ['v' => form fields, 'paths' => uploaded files, 'password_hash' => ...].
// Throws if the database write fails; the caller shows an error.
function start_registration(string $role, string $email, string $name, array $payload): array {
    purge_expired_registrations();
    $pdo   = db();
    $token = bin2hex(random_bytes(32));
    $json  = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $pdo->beginTransaction();
    try {
        $old = $pdo->prepare('DELETE FROM pending_registrations WHERE email = ? RETURNING payload');
        $old->execute([$email]);
        $oldPayloads = $old->fetchAll(PDO::FETCH_COLUMN);
        $pdo->prepare('INSERT INTO pending_registrations (role, email, full_name, token_hash, payload, expires_at)
                       VALUES (?, ?, ?, ?, CAST(? AS jsonb), NOW() + make_interval(hours => CAST(? AS integer)))')
            ->execute([$role, $email, $name, hash('sha256', $token), $json, PENDING_HOURS]);   // only the hash is stored
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    foreach ($oldPayloads as $p) pending_drop_files(json_decode((string)$p, true) ?: []);   // files of the replaced sign-up
    return send_verification_link($token, $name, $email, $role);
}

// New link for a waiting sign-up; older links stop working. ['found' => false] when nothing is waiting for that email.
function resend_pending_verification(string $email): array {
    purge_expired_registrations();
    $token = bin2hex(random_bytes(32));
    $u = db()->prepare('UPDATE pending_registrations
                        SET token_hash = ?, expires_at = NOW() + make_interval(hours => CAST(? AS integer))
                        WHERE email = ? RETURNING role, full_name');
    $u->execute([hash('sha256', $token), PENDING_HOURS, $email]);
    $row = $u->fetch();
    if (!$row) return ['found' => false, 'sent' => false, 'dev_link' => null];
    return ['found' => true] + send_verification_link($token, (string)$row['full_name'], $email, (string)$row['role']);
}

// True when a waiting sign-up exists for this email AND the password matches it.
// Lets the login page say "please verify your email" to the real owner only, without revealing anything to others.
function pending_password_matches(string $email, string $password): bool {
    $s = db()->prepare('SELECT payload FROM pending_registrations WHERE email = ? AND expires_at > NOW()');
    $s->execute([$email]);
    $payload = $s->fetchColumn();
    if ($payload === false) return false;
    $p = json_decode((string)$payload, true) ?: [];
    return !empty($p['password_hash']) && password_verify($password, (string)$p['password_hash']);
}

// Creates the real, active account (users + profile rows) from a sign-up. The CALLER owns the transaction.
// Returns the new user id.
function insert_account(PDO $pdo, string $role, string $email, string $name, array $p): int {
    if ($role === 'employer') {
        $v = $p['v'] ?? []; $paths = $p['paths'] ?? [];
        $pdo->prepare("INSERT INTO users (role, full_name, email, phone, password_hash, status) VALUES ('employer', ?, ?, ?, ?, 'active')")
            ->execute([$name, $email, $v['contact_phone'], $p['password_hash']]);
        $uid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO employer_profiles
            (user_id, employer_name, employer_type, description, barangay_id, full_address, landmark, contact_person, contact_position,
             contact_phone, facebook_url, permit_number, permit_expiry, dti_sec_number, barangay_cert_path, permit_doc_path,
             workplace_photo_path, id_doc_path, selfie_path, agreed_terms_at, verification_status)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),'pending')")
            ->execute([$uid, $v['employer_name'], $v['type'], $v['description'] ?: null, (int)$v['barangay'], $v['address'],
                $v['landmark'] ?: null, $v['contact_person'], $v['contact_position'] ?: null, $v['contact_phone'],
                $v['facebook'] ?: null, $v['permit_number'] ?: null, $v['type'] === 'registered_business' ? $v['permit_expiry'] : null,
                $v['dti'] ?: null, $paths['barangay_cert'] ?? null, $paths['permit_doc'] ?? null, $paths['workplace_photo'] ?? null,
                $paths['id_doc'] ?? null, $paths['selfie'] ?? null]);
        $ref = $pdo->prepare('INSERT INTO employer_references (employer_id, ref_name, ref_phone, relationship) VALUES (?,?,?,?)');
        foreach ([1, 2] as $n) $ref->execute([$uid, $v["ref{$n}_name"], $v["ref{$n}_phone"], $v["ref{$n}_rel"]]);
        return $uid;
    }
    $pdo->prepare("INSERT INTO users (role, full_name, email, phone, password_hash, status) VALUES ('seeker', ?, ?, ?, ?, 'active')")
        ->execute([$name, $email, $p['phone'] ?? null, $p['password_hash']]);
    $uid = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO seeker_profiles (user_id) VALUES (?)')->execute([$uid]);
    return $uid;
}

// Called when the person opens the link. Creates the account and removes the waiting row.
// Returns 'ok', 'exists' (that email already has an account), 'invalid' (unknown, used, or expired link) or 'error'.
function complete_registration(string $token): string {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return 'invalid';
    purge_expired_registrations();
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $s = $pdo->prepare('SELECT id, role, email, full_name, payload FROM pending_registrations
                            WHERE token_hash = ? AND expires_at > NOW() FOR UPDATE');   // locked, so a double click cannot create it twice
        $s->execute([hash('sha256', $token)]);
        $row = $s->fetch();
        if (!$row) { $pdo->rollBack(); return 'invalid'; }
        $payload = json_decode((string)$row['payload'], true) ?: [];

        $has = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
        $has->execute([$row['email']]);
        if ($has->fetchColumn()) {   // someone made an account with this email in the meantime (for example with Google)
            $pdo->prepare('DELETE FROM pending_registrations WHERE id = ?')->execute([$row['id']]);
            $pdo->commit();
            pending_drop_files($payload);
            return 'exists';
        }
        insert_account($pdo, (string)$row['role'], (string)$row['email'], (string)$row['full_name'], $payload);
        $pdo->prepare('DELETE FROM pending_registrations WHERE id = ?')->execute([$row['id']]);
        $pdo->commit();
        return 'ok';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException && $e->getCode() === '23505') return 'exists';   // email already taken
        error_log('complete_registration failed: ' . $e->getMessage());
        return 'error';
    }
}

// Sets the message the person sees after signing up.
function verification_flash(array $r, string $email, string $prefix): void {
    $_SESSION['resend_email'] = $email;   // makes the "Resend it" link appear on the login page for this visitor only
    if ($r['sent']) {
        flash('success', $prefix . ' We sent a link to ' . $email . '. Open it to create your account (check spam too).');
    } elseif (!empty($r['dev_link'])) {
        flash('info', $prefix . ' Local testing: the email could not be sent, so open this link: ' . $r['dev_link']);
    } else {
        flash('info', $prefix . ' But the email could not be sent. Use "Resend it" on this page.');
    }
}

// ---------------------------------------------------------------------------------------------
// LEGACY: accounts made by the old flow that are still 'pending_email' in the users table.
// After you run database/migrations/cleanup_old_pending_accounts.sql there are none left, and this
// function (and the "legacy" parts of verify-email.php and resend-verification.php) can be deleted.
// ---------------------------------------------------------------------------------------------
function issue_verification(int $uid, string $name, string $email, string $role = 'seeker'): array {
    $token = bin2hex(random_bytes(32));
    $u = db()->prepare("UPDATE users SET email_token = ? WHERE id = ? AND status = 'pending_email'");
    $u->execute([hash('sha256', $token), $uid]);
    if ($u->rowCount() < 1) return ['sent' => false, 'dev_link' => null];
    return send_verification_link($token, $name, $email, $role);
}
