<?php
// Email verification: sends a "confirm your email" link after sign-up, so only real, working emails can be used.
// Uses the same email sender and layout as the password reset (includes/password_reset.php).
require_once __DIR__ . '/password_reset.php';

// The "confirm your email" message. $role is 'seeker' or 'employer'.
function build_verify_email(string $name, string $email, string $link, string $role = 'seeker'): array {
    $F       = 'Arial,Helvetica,sans-serif';
    $contact = absolute_url('contact.php');
    $next    = $role === 'employer'
        ? 'After you confirm your email, our admin team will review your employer application. You can post jobs once it is approved.'
        : 'After you confirm your email, you can log in and start looking for jobs near you.';

    ob_start(); ?>
      <h1 style="margin:0 0 18px;font-family:<?= $F ?>;font-size:24px;line-height:1.3;color:#12281E;">Confirm your email address</h1>
      <p style="margin:0 0 14px;">Hello <b><?= e($name) ?></b>,</p>
      <p style="margin:0 0 14px;">Thank you for signing up for Trabaho Tu Bunawan with <b><?= e($email) ?></b>. Please confirm that this email address is yours by clicking the button below.</p>

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
          <b>Did not sign up?</b> You can safely ignore this email. The account stays inactive until this link is opened.
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
          . "You can safely ignore this email. The account stays inactive until this link is opened.\n\n"
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

// Creates a fresh verification link for an unverified account and emails it. Any older link stops working.
// Returns ['sent' => bool, 'dev_link' => string|null]. dev_link is only filled in on your own computer (XAMPP).
function issue_verification(int $uid, string $name, string $email, string $role = 'seeker'): array {
    $token = bin2hex(random_bytes(32));
    $u = db()->prepare("UPDATE users SET email_token = ? WHERE id = ? AND status = 'pending_email'");
    $u->execute([hash('sha256', $token), $uid]);          // only the hash is stored, never the link itself
    if ($u->rowCount() < 1) return ['sent' => false, 'dev_link' => null];
    $link = absolute_url('verify-email.php?token=' . $token);
    $mail = build_verify_email($name, $email, $link, $role);
    $ok   = send_site_mail($email, $mail['subject'], $mail['text'], $mail['html'], $name);
    return ['sent' => $ok, 'dev_link' => (!$ok && is_local_request()) ? $link : null];
}

// Sets the message the person sees after signing up.
function verification_flash(array $r, string $email, string $prefix): void {
    if ($r['sent']) {
        flash('success', $prefix . ' We sent a verification link to ' . $email . '. Open it to activate your account, then log in. Check your spam folder if you do not see it.');
    } elseif (!empty($r['dev_link'])) {
        flash('info', $prefix . ' Local testing: the email could not be sent, so open this link: ' . $r['dev_link']);
    } else {
        flash('info', $prefix . ' But we could not send the verification email right now. Use "Resend it" on the login page to get a new link.');
    }
}
