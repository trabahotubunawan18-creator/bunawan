<?php
// Employer registration wizard. Job seekers register on login.php.
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/upload.php';
require __DIR__ . '/includes/verify_email.php';
require __DIR__ . '/includes/google_auth.php';

if (($_GET['role'] ?? '') !== 'employer') redirect('login.php?tab=register');
if (is_logged_in()) {
    if (($_SESSION['role'] ?? '') === 'employer') redirect('employer/index.php');
    flash('info', 'You are logged in with another account. Log out first to register an employer account.');
    redirect(dashboard_url($_SESSION['role']));
}

$pdo = db();
$barangays = $pdo->query('SELECT id, name FROM barangays WHERE is_active = 1 ORDER BY name')->fetchAll();
$errors = [];
$add = function (int $step, string $msg) use (&$errors) { $errors[] = [$step, $msg]; };

// Signed in with Google? Then step 1 is already done: name and email come from Google, and there is no password.
if (($_GET['google'] ?? '') === 'off') { unset($_SESSION['google_signup']); redirect('register.php?role=employer'); }
$g = $_SESSION['google_signup'] ?? null;
if ($g && (time() - (int)($g['time'] ?? 0) > 7200)) { unset($_SESSION['google_signup']); $g = null; }

$fields = ['full_name', 'email', 'type', 'employer_name', 'description', 'barangay', 'address', 'landmark',
    'contact_person', 'contact_position', 'contact_phone', 'facebook', 'permit_number', 'permit_expiry', 'dti',
    'ref1_name', 'ref1_phone', 'ref1_rel', 'ref2_name', 'ref2_phone', 'ref2_rel'];
$v = array_fill_keys($fields, '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $add(4, 'The files you chose are too large. Please use files of 3 MB or less.');
    } elseif (!csrf_check()) {
        $add(1, 'Your session expired. Please try again.');
    } elseif (rate_count('register', 60) >= 5) {
        $add(1, 'Too many sign-ups from this device. Please try again later.');
    } else {
        foreach ($fields as $k) $v[$k] = trim((string)($_POST[$k] ?? ''));
        $v['email'] = strtolower($v['email']);
        if ($g) $v['email'] = $g['email'];   // never trust the form for a Google email
        $pass  = $_POST['password'] ?? '';
        $pass2 = $_POST['password2'] ?? '';
        $normPhone = fn($p) => preg_replace('/[\s\-()]/', '', $p);
        $mobile = '/^(09|\+639)\d{9}$/';
        $idfmt  = '/^[A-Za-z0-9\-\/\. ]{3,60}$/u';

        // Step 1: account
        if (mb_strlen($v['full_name']) < 2 || mb_strlen($v['full_name']) > 100) $add(1, 'Enter your full name.');
        if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL) || strlen($v['email']) > 190) $add(1, 'Enter a valid email address.');
        if (!$g) {
            if ($pw_err = password_problem($pass)) $add(1, $pw_err);
            if ($pass !== $pass2) $add(1, 'The two passwords do not match.');
        }

        // Step 2: type and name
        $types = ['registered_business', 'organization', 'individual'];
        if (!in_array($v['type'], $types, true)) $add(2, 'Choose what type of employer you are.');
        if (mb_strlen($v['employer_name']) < 2 || mb_strlen($v['employer_name']) > 150) $add(2, 'Enter your business, organization, or employer name.');
        if (mb_strlen($v['description']) > 500) $add(2, 'The description is too long (maximum 500 characters).');

        // Step 3: address and contact
        $okBarangay = false;
        foreach ($barangays as $b) if ((string)$b['id'] === $v['barangay']) $okBarangay = true;
        if (!$okBarangay) $add(3, 'Choose your barangay in Bunawan.');
        if (mb_strlen($v['address']) < 5 || mb_strlen($v['address']) > 255) $add(3, 'Enter your purok or street address (5 to 255 characters).');
        if (mb_strlen($v['landmark']) > 150) $add(3, 'The landmark is too long.');
        if (mb_strlen($v['contact_person']) < 2 || mb_strlen($v['contact_person']) > 120) $add(3, 'Enter the contact person\'s name.');
        if (mb_strlen($v['contact_position']) > 80) $add(3, 'The position is too long.');
        $v['contact_phone'] = $normPhone($v['contact_phone']);
        if (!preg_match($mobile, $v['contact_phone'])) $add(3, 'Enter a valid contact mobile number, like 09123456789.');
        if ($v['facebook'] !== '') {
            if (!preg_match('#^https?://#i', $v['facebook'])) $v['facebook'] = 'https://' . $v['facebook'];
            $host = strtolower((string)parse_url($v['facebook'], PHP_URL_HOST));
            if (!filter_var($v['facebook'], FILTER_VALIDATE_URL) || !preg_match('/(^|\.)(facebook\.com|fb\.com|fb\.me)$/', $host) || strlen($v['facebook']) > 255)
                $add(3, 'Enter a valid Facebook page link, or leave it empty.');
        }

        // Step 4: documents (text parts)
        if ($v['type'] === 'registered_business' || $v['type'] === 'organization') {
            if (!preg_match($idfmt, $v['permit_number'])) $add(4, $v['type'] === 'organization' ? 'Enter your registration or accreditation number.' : 'Enter your Business Permit number.');
        }
        if ($v['type'] === 'registered_business') {
            $d = DateTime::createFromFormat('Y-m-d', $v['permit_expiry']);
            if (!$d || $d->format('Y-m-d') !== $v['permit_expiry']) $add(4, 'Enter your permit expiry date.');
            elseif ($d < new DateTime('today')) $add(4, 'Your permit has expired. Please renew it before registering.');
        }
        if ($v['type'] === 'registered_business' && $v['dti'] === '') $add(4, 'Enter your DTI or SEC registration number.');
        elseif ($v['dti'] !== '' && !preg_match($idfmt, $v['dti'])) $add(4, 'The DTI or SEC number looks invalid.');

        // Step 5: references
        foreach ([1, 2] as $n) {
            $v["ref{$n}_phone"] = $normPhone($v["ref{$n}_phone"]);
            if (mb_strlen($v["ref{$n}_name"]) < 2 || mb_strlen($v["ref{$n}_name"]) > 120) $add(5, "Enter the name of reference $n.");
            if (!preg_match('/^\+?\d{7,13}$/', $v["ref{$n}_phone"])) $add(5, "Enter a valid phone number for reference $n.");
            if (mb_strlen($v["ref{$n}_rel"]) < 2 || mb_strlen($v["ref{$n}_rel"]) > 80) $add(5, "Tell us how reference $n knows you (for example barangay official, supplier).");
        }
        if ($v['ref1_phone'] !== '' && $v['ref1_phone'] === $v['ref2_phone']) $add(5, 'Please give two different references.');

        // Step 6: agreements
        foreach (['agree_fees', 'agree_honest', 'agree_privacy'] as $a) {
            if (empty($_POST[$a])) { $add(6, 'Please tick all three agreements to continue.'); break; }
        }

        // Email must be new
        if (!$errors) {
            $s = $pdo->prepare('SELECT id, status FROM users WHERE email = ?');
            $s->execute([$v['email']]);
            if ($row = $s->fetch()) {
                $add(1, $row['status'] === 'pending_email'
                    ? 'This email already has a registration waiting to be verified. Open the verification email we sent, or go to the login page and choose "Resend it".'
                    : 'That email is already registered. Try logging in instead.');
            }
        }

        // Uploads (only when everything else is valid, so we do not store files for a failed form)
        $saved = [];
        $paths = ['workplace_photo' => null, 'permit_doc' => null, 'barangay_cert' => null, 'id_doc' => null, 'selfie' => null];
        if (!$errors) {
            foreach ($paths as $field => $_) {
                [$rel, $upErr] = save_private_upload($_FILES[$field] ?? [], in_array($field, ['id_doc', 'selfie'], true) ? ID_TYPES : DOC_TYPES, 3 * 1048576, 'verification');
                if ($upErr) $add(4, ucfirst(str_replace('_', ' ', $field)) . ': ' . $upErr);
                $paths[$field] = $rel;
                if ($rel) $saved[] = $rel;
            }
            if (!$paths['workplace_photo'] && !$errors) $add(4, $v['type'] === 'organization' ? 'Please upload a photo of your office, signage, or the place where you operate.' : 'Please upload a photo of your storefront, signage, or workplace.');
            if ($v['type'] === 'registered_business' && !$paths['permit_doc'] && !$errors) $add(4, 'Please upload a photo or scan of your Business Permit.');
            if ($v['type'] === 'organization' && !$paths['permit_doc'] && !$errors) $add(4, 'Please upload your registration or accreditation document (for example an SEC or CDA certificate, or an accreditation letter).');
            if ($v['type'] === 'individual' && !$paths['barangay_cert'] && !$errors) $add(4, 'Please upload your Barangay Certification.');
            if (!$paths['id_doc'] && !$errors) $add(4, 'Please upload a photo of a valid government-issued ID of the contact person.');
            if (!$paths['selfie'] && !$errors) $add(4, 'Please upload a selfie of the contact person holding the same ID.');
        }

        if (!$errors) {
            rate_hit('register');
            try {
                $pdo->beginTransaction();
                $status = ($g || !REQUIRE_EMAIL_VERIFY) ? 'active' : 'pending_email';   // Google already confirmed the email
                $token  = null;   // the real (hashed) verification link is created by issue_verification() after saving
                $pdo->prepare("INSERT INTO users (role, full_name, email, phone, password_hash, status, email_token) VALUES ('employer',?,?,?,?,?,?)")
                    ->execute([$v['full_name'], $v['email'], $v['contact_phone'], password_hash($g ? bin2hex(random_bytes(32)) : $pass, PASSWORD_DEFAULT), $status, $token]);
                $uid = (int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO employer_profiles
                    (user_id, employer_name, employer_type, description, barangay_id, full_address, landmark, contact_person, contact_position,
                     contact_phone, facebook_url, permit_number, permit_expiry, dti_sec_number, barangay_cert_path, permit_doc_path,
                     workplace_photo_path, id_doc_path, selfie_path, agreed_terms_at, verification_status)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),'pending')")
                    ->execute([$uid, $v['employer_name'], $v['type'], $v['description'] ?: null, (int)$v['barangay'], $v['address'],
                        $v['landmark'] ?: null, $v['contact_person'], $v['contact_position'] ?: null, $v['contact_phone'],
                        $v['facebook'] ?: null, $v['permit_number'] ?: null, $v['type'] === 'registered_business' ? $v['permit_expiry'] : null,
                        $v['dti'] ?: null, $paths['barangay_cert'], $paths['permit_doc'], $paths['workplace_photo'], $paths['id_doc'], $paths['selfie']]);
                $ref = $pdo->prepare('INSERT INTO employer_references (employer_id, ref_name, ref_phone, relationship) VALUES (?,?,?,?)');
                foreach ([1, 2] as $n) $ref->execute([$uid, $v["ref{$n}_name"], $v["ref{$n}_phone"], $v["ref{$n}_rel"]]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                foreach ($saved as $f) delete_private($f);
                error_log('Employer registration failed: ' . $e->getMessage());
                $add(1, 'Something went wrong while saving your application. Please try again.');
            }
            if (!$errors) {
                unset($_SESSION['google_signup']);
                if (REQUIRE_EMAIL_VERIFY && !$g) {
                    verification_flash(issue_verification($uid, $v['full_name'], $v['email'], 'employer'), $v['email'], 'Application received.');
                    redirect('login.php');
                }
                session_regenerate_id(true);
                $_SESSION['uid'] = $uid; $_SESSION['role'] = 'employer'; $_SESSION['name'] = $v['full_name'];
                flash('success', 'Application submitted. Our admin team will review it and you will see the result here.');
                redirect('employer/index.php');
            }
        } else {
            foreach ($saved as $f) delete_private($f);
        }
    }
}

if ($g) {   // keep the Google details in step 1
    $v['email'] = $g['email'];
    if ($v['full_name'] === '') $v['full_name'] = $g['name'];
}
$startStep = $errors ? min(array_column($errors, 0)) : 1;
$labels = ['Account', 'Employer type', 'Address', 'Documents', 'References', 'Agreements'];
$pageTitle = 'Employer Registration';
$bodyClass = 'reg-page';
require __DIR__ . '/includes/header.php';
function val(array $v, string $k): string { return e($v[$k] ?? ''); }
?>
<section class="page-hero reg-hero">
  <div class="wrap">
    <h1>Register as an employer</h1>
    <p>Our team verifies every employer before they can post jobs. This keeps job seekers safe.</p>
  </div>
</section>

<section class="reg-main">
  <div class="wrap">
    <div class="reg-shell">
      <ol class="progress" aria-label="Registration steps">
        <?php foreach ($labels as $i => $l): ?><li data-p="<?= $i + 1 ?>"><?= e($l) ?></li><?php endforeach; ?>
      </ol>

      <?php if ($errors): ?>
        <div class="alert alert-error" role="alert"><ul>
          <?php foreach ($errors as [$st, $m]): ?><li><b>Step <?= (int)$st ?>:</b> <?= e($m) ?></li><?php endforeach; ?>
        </ul>
        <?php if (!empty($_FILES)): ?><p class="small" style="margin:8px 0 0">For security, please choose your files again.</p><?php endif; ?></div>
      <?php endif; ?>

      <form id="wiz" class="form reg-form" method="post" enctype="multipart/form-data" data-start="<?= (int)$startStep ?>"<?= $g ? ' data-google="1"' : '' ?> novalidate>
        <?= csrf_field() ?>

        <!-- Step 1 -->
        <div class="wiz-step" data-step="1">
          <div class="step-head"><span class="st-ico"><?= icon('user', 20) ?></span><h2>Create your account</h2><span class="step-count">Step 1 of 6</span></div>
          <?php if ($g): ?>
            <p class="step-sub">You are signing up with Google. You will log in with the <b>Continue with Google</b> button, so you do not need a password. <a href="<?= url('register.php?role=employer&google=off') ?>">Use email and password instead</a></p>
          <?php else: ?>
            <p class="step-sub">You will use this email and password to log in.</p>
          <?php endif; ?>
          <div class="row2">
            <div><label for="e-name">Your full name</label>
              <input id="e-name" name="full_name" maxlength="100" autocomplete="name" value="<?= val($v, 'full_name') ?>" required></div>
            <div><label for="e-email">Email</label>
              <input id="e-email" name="email" type="email" maxlength="190" autocomplete="email" value="<?= val($v, 'email') ?>"<?= $g ? ' readonly' : '' ?> required></div>
          </div>
          <div class="row2"<?= $g ? ' style="display:none"' : '' ?>>
            <div><label for="e-pass">Password</label>
              <div class="pw"><input id="e-pass" name="password" type="password" minlength="8" autocomplete="new-password"<?= $g ? ' disabled' : ' required' ?>>
                <button type="button" class="pw-btn" data-toggle-pw="e-pass">Show</button></div>
              <div class="meter"><span id="e-meter"></span></div><small class="small" id="e-meter-text">At least 8 characters with uppercase, lowercase, a number and a symbol.</small></div>
            <div><label for="e-pass2">Confirm password</label>
              <input id="e-pass2" name="password2" type="password" autocomplete="new-password"<?= $g ? ' disabled' : ' required' ?>></div>
          </div>
          <?php if (!$g) echo google_button('employer', 'Sign up with Google', true); ?>
        </div>

        <!-- Step 2 -->
        <div class="wiz-step" data-step="2">
          <div class="step-head"><span class="st-ico"><?= icon('building', 20) ?></span><h2>What type of employer are you?</h2><span class="step-count">Step 2 of 6</span></div>
          <div class="type-grid" role="radiogroup" aria-label="Employer type">
            <label class="type-opt"><input type="radio" name="type" value="registered_business" <?= $v['type'] === 'registered_business' ? 'checked' : '' ?> required>
              <span class="to-ico"><?= icon('briefcase', 20) ?></span><b>Registered business</b><small>Store, restaurant, shop, or company with a Business Permit</small></label>
            <label class="type-opt"><input type="radio" name="type" value="organization" <?= $v['type'] === 'organization' ? 'checked' : '' ?>>
              <span class="to-ico"><?= icon('users', 20) ?></span><b>Organization</b><small>Cooperative, school, NGO, or government office</small></label>
            <label class="type-opt"><input type="radio" name="type" value="individual" <?= $v['type'] === 'individual' ? 'checked' : '' ?>>
              <span class="to-ico"><?= icon('user', 20) ?></span><b>Individual or household</b><small>Farm owner, contractor, or family hiring a helper</small></label>
          </div>
          <label for="e-ename">Business, organization, or employer name</label>
          <input id="e-ename" name="employer_name" maxlength="150" value="<?= val($v, 'employer_name') ?>" placeholder="Example: Dela Cruz Farm" required>
          <label for="e-desc">Short description <span class="opt">(optional)</span></label>
          <textarea id="e-desc" name="description" rows="2" maxlength="500" placeholder="What does your business or household do?"><?= val($v, 'description') ?></textarea>
        </div>

        <!-- Step 3 -->
        <div class="wiz-step" data-step="3">
          <div class="step-head"><span class="st-ico"><?= icon('pin', 20) ?></span><h2>Address and contact</h2><span class="step-count">Step 3 of 6</span></div>
          <p class="step-sub">We only accept employers located in Bunawan, Agusan del Sur.</p>
          <h3 class="grp">Where you are</h3>
          <div class="row2">
            <div><label for="e-brgy">Barangay</label>
              <select id="e-brgy" name="barangay" required><option value="">Choose barangay</option>
                <?php foreach ($barangays as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $v['barangay'] === (string)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
              </select></div>
            <div><label for="e-addr">Purok or street</label>
              <input id="e-addr" name="address" maxlength="255" value="<?= val($v, 'address') ?>" required></div>
          </div>
          <div class="row2">
            <div><label for="e-land">Landmark <span class="opt">(optional)</span></label>
              <input id="e-land" name="landmark" maxlength="150" value="<?= val($v, 'landmark') ?>" placeholder="Example: beside the public market"></div>
            <div><label for="e-fb">Facebook page <span class="opt">(optional)</span></label>
              <input id="e-fb" name="facebook" maxlength="255" placeholder="facebook.com/yourpage" value="<?= val($v, 'facebook') ?>"></div>
          </div>
          <h3 class="grp">Who we can call</h3>
          <div class="row3">
            <div><label for="e-cp">Contact person</label>
              <input id="e-cp" name="contact_person" maxlength="120" value="<?= val($v, 'contact_person') ?>" required></div>
            <div><label for="e-pos">Position <span class="opt">(optional)</span></label>
              <input id="e-pos" name="contact_position" maxlength="80" value="<?= val($v, 'contact_position') ?>" placeholder="Owner, manager, HR"></div>
            <div><label for="e-phone">Mobile number</label>
              <input id="e-phone" name="contact_phone" type="tel" inputmode="numeric" placeholder="09123456789" value="<?= val($v, 'contact_phone') ?>" required></div>
          </div>
          <p class="step-note">Our admin will call this number as part of verification. Job seekers you shortlist or accept will also see this number and your contact name, so they can reach you.</p>
        </div>

        <!-- Step 4 -->
        <div class="wiz-step" data-step="4">
          <div class="step-head"><span class="st-ico"><?= icon('file', 20) ?></span><h2>Documents and photos</h2><span class="step-count">Step 4 of 6</span></div>
          <p class="step-sub">JPG, PNG, or PDF, up to 3 MB each. Only our admin team can see these files.</p>
          <p class="notice" id="no-type" style="display:none">Go back to step 2 and choose your employer type to see what we need.</p>
          <p class="need" data-types="registered_business" hidden><b>You will need:</b> your Business Permit (number, expiry date, and a photo or scan), your DTI or SEC number, a photo of your workplace, a valid ID, and a selfie holding that ID.</p>
          <p class="need" data-types="organization" hidden><b>You will need:</b> your registration or accreditation number and document, a photo of your office or place of operation, a valid ID, and a selfie holding that ID.</p>
          <p class="need" data-types="individual" hidden><b>You will need:</b> your Barangay Certification, a photo of your workplace, a valid ID, and a selfie holding that ID.</p>

          <h3 class="grp" data-types="registered_business organization" hidden>Registration details</h3>
          <div class="row3">
            <div data-types="registered_business organization" hidden>
              <label for="e-permit" id="permit-label">Business (Mayor's) Permit number</label>
              <input id="e-permit" name="permit_number" maxlength="60" value="<?= val($v, 'permit_number') ?>"></div>
            <div data-types="registered_business" hidden>
              <label for="e-exp">Permit expiry date</label>
              <input id="e-exp" name="permit_expiry" type="date" min="<?= date('Y-m-d') ?>" value="<?= val($v, 'permit_expiry') ?>"></div>
            <div data-types="registered_business" hidden>
              <label for="e-dti">DTI or SEC number</label>
              <input id="e-dti" name="dti" maxlength="60" value="<?= val($v, 'dti') ?>"></div>
          </div>

          <h3 class="grp">Upload your files</h3>
          <div class="up-grid">
            <div data-types="registered_business organization" hidden>
              <label class="up" for="e-permitdoc">
                <span class="up-ico"><?= icon('file', 22) ?></span>
                <span class="up-body">
                  <span class="up-top"><b class="up-title" id="permitdoc-label">Photo or scan of your permit or registration document</b><em class="up-tag"></em></span>
                  <small class="up-hint" id="permitdoc-hint">Make sure the permit number and expiry date can be read.</small>
                  <span class="up-file"><span class="up-thumb"></span><span class="up-meta"><span class="up-name"></span><span class="up-size"></span></span><button type="button" class="up-x" data-clear aria-label="Remove file">&times;</button></span>
                  <span class="up-pick">Choose file</span>
                </span>
                <input id="e-permitdoc" name="permit_doc" type="file" accept=".jpg,.jpeg,.png,.pdf">
              </label>
            </div>
            <div data-types="individual" hidden>
              <label class="up" for="e-cert">
                <span class="up-ico"><?= icon('file', 22) ?></span>
                <span class="up-body">
                  <span class="up-top"><b class="up-title">Barangay Certification</b><em class="up-tag"></em></span>
                  <small class="up-hint">Shows that you live or operate in Bunawan.</small>
                  <span class="up-file"><span class="up-thumb"></span><span class="up-meta"><span class="up-name"></span><span class="up-size"></span></span><button type="button" class="up-x" data-clear aria-label="Remove file">&times;</button></span>
                  <span class="up-pick">Choose file</span>
                </span>
                <input id="e-cert" name="barangay_cert" type="file" accept=".jpg,.jpeg,.png,.pdf">
              </label>
            </div>
            <div>
              <label class="up" for="e-photo">
                <span class="up-ico"><?= icon('pin', 22) ?></span>
                <span class="up-body">
                  <span class="up-top"><b class="up-title" id="photo-label">Photo of your storefront, signage, or workplace</b><em class="up-tag"></em></span>
                  <small class="up-hint" id="photo-hint">Show your signage or the front of your place.</small>
                  <span class="up-file"><span class="up-thumb"></span><span class="up-meta"><span class="up-name"></span><span class="up-size"></span></span><button type="button" class="up-x" data-clear aria-label="Remove file">&times;</button></span>
                  <span class="up-pick">Choose photo</span>
                </span>
                <input id="e-photo" name="workplace_photo" type="file" accept=".jpg,.jpeg,.png">
              </label>
            </div>
            <div>
              <label class="up" for="e-iddoc">
                <span class="up-ico"><?= icon('file', 22) ?></span>
                <span class="up-body">
                  <span class="up-top"><b class="up-title">Valid ID of the contact person</b><em class="up-tag"></em></span>
                  <small class="up-hint">Government-issued ID (for example PhilSys, driver's license, passport, UMID). Name and photo must be readable.</small>
                  <span class="up-file"><span class="up-thumb"></span><span class="up-meta"><span class="up-name"></span><span class="up-size"></span></span><button type="button" class="up-x" data-clear aria-label="Remove file">&times;</button></span>
                  <span class="up-pick">Choose photo</span>
                </span>
                <input id="e-iddoc" name="id_doc" type="file" accept=".jpg,.jpeg,.png">
              </label>
            </div>
            <div>
              <label class="up" for="e-selfie">
                <span class="up-ico"><?= icon('user', 22) ?></span>
                <span class="up-body">
                  <span class="up-top"><b class="up-title">Selfie holding your ID</b><em class="up-tag"></em></span>
                  <small class="up-hint">Hold the same ID next to your face. Your face and the ID must both be clear.</small>
                  <span class="up-file"><span class="up-thumb"></span><span class="up-meta"><span class="up-name"></span><span class="up-size"></span></span><button type="button" class="up-x" data-clear aria-label="Remove file">&times;</button></span>
                  <span class="up-pick">Choose photo</span>
                </span>
                <input id="e-selfie" name="selfie" type="file" accept=".jpg,.jpeg,.png">
              </label>
            </div>
          </div>
        </div>

        <!-- Step 5 -->
        <div class="wiz-step" data-step="5">
          <div class="step-head"><span class="st-ico"><?= icon('users', 20) ?></span><h2>Two local references</h2><span class="step-count">Step 5 of 6</span></div>
          <p class="step-sub">People in Bunawan who know you or your business, such as a barangay official, supplier, or employee. Our admin may call one of them.</p>
          <div class="refgrid">
          <?php foreach ([1, 2] as $n): ?>
            <fieldset class="refset"><legend>Reference <?= $n ?></legend>
              <label for="r<?= $n ?>n">Full name</label>
              <input id="r<?= $n ?>n" name="ref<?= $n ?>_name" maxlength="120" value="<?= val($v, "ref{$n}_name") ?>" required>
              <label for="r<?= $n ?>p">Phone number</label>
              <input id="r<?= $n ?>p" name="ref<?= $n ?>_phone" type="tel" value="<?= val($v, "ref{$n}_phone") ?>" required>
              <label for="r<?= $n ?>r">How do they know you?</label>
              <input id="r<?= $n ?>r" name="ref<?= $n ?>_rel" maxlength="80" value="<?= val($v, "ref{$n}_rel") ?>" placeholder="Example: Kagawad, supplier" required>
            </fieldset>
          <?php endforeach; ?>
          </div>
        </div>

        <!-- Step 6 -->
        <div class="wiz-step" data-step="6">
          <div class="step-head"><span class="st-ico"><?= icon('shield', 20) ?></span><h2>Agreements</h2><span class="step-count">Step 6 of 6</span></div>
          <p class="step-sub">Please read and tick all three to continue.</p>
          <label class="check-row"><input type="checkbox" name="agree_fees" value="1" required> <span>I will <b>never charge applicants</b> any placement, training, or application fee.</span></label>
          <label class="check-row"><input type="checkbox" name="agree_honest" value="1" required> <span>All information and job posts I submit will be <b>true and honest</b>.</span></label>
          <label class="check-row"><input type="checkbox" name="agree_privacy" value="1" required> <span>I consent to Trabaho Tu Bunawan collecting and reviewing my information and documents for verification, under the Data Privacy Act of 2012 (RA 10173). Read the <a href="<?= url('privacy.php') ?>" target="_blank">Privacy Policy</a> and <a href="<?= url('terms.php') ?>" target="_blank">Terms</a>.</span></label>
          <p class="step-note step-note-gold">After you submit, your account is created in <b>Pending</b> status. You can post jobs once our admin approves your verification.</p>
        </div>

        <div class="wiz-nav">
          <button type="button" class="btn btn-outline" data-back>Back</button>
          <button type="button" class="btn btn-primary" data-next>Next</button>
          <button type="submit" class="btn btn-primary" data-submit>Submit for verification</button>
        </div>
      </form>
    </div>
    <p class="center small reg-foot">Already registered? <a href="<?= url('login.php') ?>">Log in</a>. Looking for work? <a href="<?= url('login.php?tab=register') ?>">Register as a job seeker</a>.</p>
  </div>
</section>
<script src="<?= url('assets/js/wizard.js') ?>"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
