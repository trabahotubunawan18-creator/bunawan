<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

$errors = [];
$v = ['name' => '', 'email' => '', 'subject' => '', 'message' => ''];

// Logged-in seekers/employers: the email is fixed to their account email, so the
// admin's reply notification always reaches their account. Name is prefilled.
$lockEmail = '';
if (is_logged_in() && in_array($_SESSION['role'] ?? '', ['seeker', 'employer'], true)) {
    $me = db()->prepare('SELECT full_name, email FROM users WHERE id = ?');
    $me->execute([(int)$_SESSION['uid']]);
    if ($r = $me->fetch()) {
        $lockEmail = strtolower((string)$r['email']);
        $v['email'] = $lockEmail;
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $v['name'] = (string)$r['full_name'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!empty($_POST['website'])) {
        // Hidden spam-trap field was filled in: pretend it worked.
        flash('success', 'Thank you. Your message has been sent.'); redirect('contact.php');
    } else {
        foreach ($v as $k => $_) $v[$k] = trim($_POST[$k] ?? '');
        $v['email'] = $lockEmail !== '' ? $lockEmail : strtolower($v['email']);
        if (rate_count('contact', 60) >= 3) $errors[] = 'You have sent several messages recently. Please try again in an hour.';
        if (mb_strlen($v['name']) < 2 || mb_strlen($v['name']) > 100) $errors[] = 'Enter your name (2 to 100 characters).';
        if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL) || strlen($v['email']) > 190) $errors[] = 'Enter a valid email address.';
        if (mb_strlen($v['subject']) > 150) $errors[] = 'The subject is too long (maximum 150 characters).';
        if (mb_strlen($v['message']) < 10 || mb_strlen($v['message']) > 2000) $errors[] = 'Your message should be 10 to 2000 characters.';
        if (!$errors) {
            rate_hit('contact');
            db()->prepare('INSERT INTO contact_messages (name, email, subject, message, ip_address) VALUES (?,?,?,?,?)')
                ->execute([$v['name'], $v['email'], $v['subject'] ?: null, $v['message'], client_ip()]);
            flash('success', 'Thank you. Your message has been sent to our team.');
            redirect('contact.php');
        }
    }
}
$pageTitle = 'Contact Us';
$bodyClass = 'contact-page';
$inDash = is_logged_in() && ($_SESSION['role'] ?? '') === 'employer'; // employers stay inside their dashboard
require __DIR__ . ($inDash ? '/includes/emp_top.php' : '/includes/header.php');
?>
<?php if ($inDash): ?>
<div class="emp-page emp-contact">
  <div class="back-row-emp" style="margin-bottom:14px">
    <a class="back-btn" href="<?= e(url('employer/settings.php')) ?>">
      <svg class="ico" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>
      <span>Back</span>
    </a>
  </div>
  <div class="emp-hero">
    <div>
      <h1>Contact us</h1>
      <p>Questions, feedback, or something to report? Send us a message.</p>
    </div>
  </div>
<?php else: ?>
<section class="page-hero">
  <div class="wrap">
    <h1>Contact us</h1>
    <p>Questions, feedback, or something to report? Send us a message.</p>
  </div>
</section>

<section class="about-band">
  <div class="wrap">
<?php endif; ?>
    <div class="contact-grid">
      <div class="card contact-card">
        <?php if ($errors): ?><div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <form method="post" class="form contact-form" novalidate>
          <?= csrf_field() ?>
          <div class="hp" aria-hidden="true"><label for="website">Leave this empty</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
          <div class="cf-row">
            <div class="cf-field">
              <label for="c-name">Your name</label>
              <input id="c-name" name="name" maxlength="100" value="<?= e($v['name']) ?>" required>
            </div>
            <div class="cf-field">
              <label for="c-email">Email</label>
              <input id="c-email" name="email" type="email" maxlength="190" value="<?= e($v['email']) ?>" required<?= $lockEmail !== '' ? ' readonly aria-describedby="c-email-note"' : '' ?>>
              <?php if ($lockEmail !== ''): ?><small id="c-email-note" style="display:block;margin-top:4px;color:#6B7C72">We will reply to your account email.</small><?php endif; ?>
            </div>
          </div>
          <div class="cf-field">
            <label for="c-subject">Subject (optional)</label>
            <input id="c-subject" name="subject" maxlength="150" value="<?= e($v['subject']) ?>">
          </div>
          <div class="cf-field">
            <label for="c-msg">Message</label>
            <textarea id="c-msg" name="message" rows="4" maxlength="2000" required><?= e($v['message']) ?></textarea>
          </div>
          <button class="btn btn-primary" type="submit"><?= icon('send', 16) ?> Send message</button>
        </form>
      </div>
      <aside class="contact-info">
        <div class="info-card">
          <span class="info-ico"><?= icon('pin', 22) ?></span>
          <div><h3>Where we serve</h3><p>Bunawan, Agusan del Sur, Philippines.</p></div>
        </div>
        <div class="info-card info-warm">
          <span class="info-ico"><?= icon('building', 22) ?></span>
          <div><h3>For employers</h3><p>Questions about verification or posting? Mention your business name so we can help faster.</p></div>
        </div>
        <div class="info-card info-dark">
          <span class="info-ico"><?= icon('flag', 22) ?></span>
          <div><h3>To report a scam</h3><p>Use the <b>Report this listing</b> button on the job page. It goes straight to our admin team.</p></div>
        </div>
      </aside>
    </div>
<?php if ($inDash): ?>
</div>
<?php require __DIR__ . '/includes/dash_bottom.php'; ?>
<?php else: ?>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
<?php endif; ?>
