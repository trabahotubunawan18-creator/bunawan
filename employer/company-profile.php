<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/upload.php';
require_role('employer');
$pdo = db(); $uid = (int)$_SESSION['uid'];
$barangays = $pdo->query('SELECT id, name FROM barangays WHERE is_active = 1 ORDER BY name')->fetchAll();

$s = $pdo->prepare('SELECT * FROM employer_profiles WHERE user_id = ?');
$s->execute([$uid]);
$p = $s->fetch();
if (!$p) { http_response_code(404); exit('Employer profile not found.'); }
$locked = in_array($p['verification_status'], ['verified_business', 'verified_local'], true); // key details are locked after verification
$typeLabel = ['registered_business' => 'Registered business', 'organization' => 'Organization', 'individual' => 'Individual or household'][$p['employer_type']];

$v = ['employer_name' => $p['employer_name'], 'description' => $p['description'] ?? '', 'barangay' => (string)$p['barangay_id'],
      'address' => $p['full_address'], 'landmark' => $p['landmark'] ?? '', 'contact_person' => $p['contact_person'],
      'contact_position' => $p['contact_position'] ?? '', 'contact_phone' => $p['contact_phone'], 'facebook' => $p['facebook_url'] ?? ''];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) $errors[] = 'The image is too large. Please use one under 1 MB.';
    elseif (!csrf_check()) $errors[] = 'Your session expired. Please try again.';
    else {
        foreach ($v as $k => $_) if (isset($_POST[$k])) $v[$k] = trim((string)$_POST[$k]);
        if ($locked) { $v['employer_name'] = $p['employer_name']; $v['barangay'] = (string)$p['barangay_id']; $v['address'] = $p['full_address']; }
        $v['contact_phone'] = preg_replace('/[\s\-()]/', '', $v['contact_phone']);

        if (mb_strlen($v['employer_name']) < 2 || mb_strlen($v['employer_name']) > 150) $errors[] = 'Enter your employer name.';
        if (mb_strlen($v['description']) > 500) $errors[] = 'The description is too long (maximum 500 characters).';
        $okB = false; foreach ($barangays as $b) if ((string)$b['id'] === $v['barangay']) $okB = true;
        if (!$okB) $errors[] = 'Choose your barangay.';
        if (mb_strlen($v['address']) < 5 || mb_strlen($v['address']) > 255) $errors[] = 'Enter your purok or street address.';
        if (mb_strlen($v['landmark']) > 150) $errors[] = 'The landmark is too long.';
        if (mb_strlen($v['contact_person']) < 2 || mb_strlen($v['contact_person']) > 120) $errors[] = 'Enter the contact person\'s name.';
        if (mb_strlen($v['contact_position']) > 80) $errors[] = 'The position is too long.';
        if (!preg_match('/^(09|\+639)\d{9}$/', $v['contact_phone'])) $errors[] = 'Enter a valid contact mobile number.';
        if ($v['facebook'] !== '') {
            if (!preg_match('#^https?://#i', $v['facebook'])) $v['facebook'] = 'https://' . $v['facebook'];
            $host = strtolower((string)parse_url($v['facebook'], PHP_URL_HOST));
            if (!filter_var($v['facebook'], FILTER_VALIDATE_URL) || !preg_match('/(^|\.)(facebook\.com|fb\.com|fb\.me)$/', $host) || strlen($v['facebook']) > 255) $errors[] = 'Enter a valid Facebook page link, or leave it empty.';
        }

        $newLogo = null;
        if (!$errors) {
            [$newLogo, $err] = save_public_image($_FILES['logo'] ?? [], 'logos', 1048576);
            if ($err) $errors[] = $err;
        }
        if (!$errors) {
            $logo = $p['logo_path'];
            if ($newLogo) { delete_public($logo); $logo = $newLogo; }
            elseif (!empty($_POST['remove_logo'])) { delete_public($logo); $logo = null; }
            $pdo->prepare('UPDATE employer_profiles SET employer_name=?, description=?, barangay_id=?, full_address=?, landmark=?, contact_person=?,
                    contact_position=?, contact_phone=?, facebook_url=?, logo_path=? WHERE user_id = ?')
                ->execute([$v['employer_name'], $v['description'] ?: null, (int)$v['barangay'], $v['address'], $v['landmark'] ?: null,
                    $v['contact_person'], $v['contact_position'] ?: null, $v['contact_phone'], $v['facebook'] ?: null, $logo, $uid]);
            $pdo->prepare('UPDATE users SET phone = ? WHERE id = ?')->execute([$v['contact_phone'], $uid]);
            flash('success', 'Company profile saved.');
            redirect('employer/company-profile.php');
        } elseif ($newLogo) delete_public($newLogo);
    }
}
$pageTitle = 'Company profile';
require __DIR__ . '/../includes/emp_top.php';
?>
<header class="ep-head">
  <div><h1 class="dash-title ep-title">Company profile</h1><p class="ep-sub">This is what job seekers see on your listings.</p></div>
  <span class="ep-count"><?= e($vLabel) ?></span>
</header>
<?php if ($errors): ?><div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($locked): ?><div class="alert alert-info">Your employer name and address are locked because you are verified. To change them, <a href="<?= url('contact.php') ?>">contact us</a>.</div><?php endif; ?>

<div class="pj-grid">
<form method="post" enctype="multipart/form-data" class="form" novalidate>
  <?= csrf_field() ?>
  <div class="card panel-card">
  <h2 class="pj-h">Logo and name</h2>
  <div class="logo-row">
    <span class="av av-lg"><?php if ($p['logo_path']): ?><img src="<?= public_file_url($p['logo_path']) ?>" alt="Current logo"><?php else: ?><?= e(initials($p['employer_name'])) ?><?php endif; ?></span>
    <div style="flex:1;min-width:220px">
      <label for="c-logo" style="margin-top:0">Logo or photo (JPG or PNG, up to 1 MB)</label>
      <input id="c-logo" name="logo" type="file" accept=".jpg,.jpeg,.png">
      <?php if ($p['logo_path']): ?><label class="check-row"><input type="checkbox" name="remove_logo" value="1"> <span>Remove current logo</span></label><?php endif; ?>
    </div>
  </div>

  <div class="row2">
    <div><label for="c-name">Employer name</label><input id="c-name" name="employer_name" maxlength="150" value="<?= e($v['employer_name']) ?>" <?= $locked ? 'readonly' : 'required' ?>></div>
    <div><label>Employer type</label><input value="<?= e($typeLabel) ?>" readonly></div>
  </div>
  <label for="c-desc">Description</label>
  <textarea id="c-desc" name="description" rows="3" maxlength="500" placeholder="What does your business or household do?"><?= e($v['description']) ?></textarea>
  </div>

  <div class="card panel-card">
  <h2 class="pj-h">Location</h2>
  <div class="row2">
    <div><label for="c-b">Barangay</label>
      <select id="c-b" name="barangay" <?= $locked ? 'disabled' : 'required' ?>><option value="">Choose barangay</option>
        <?php foreach ($barangays as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $v['barangay'] === (string)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select></div>
    <div><label for="c-a">Purok or street</label><input id="c-a" name="address" maxlength="255" value="<?= e($v['address']) ?>" <?= $locked ? 'readonly' : 'required' ?>></div>
  </div>
  <label for="c-l">Landmark (optional)</label>
  <input id="c-l" name="landmark" maxlength="150" value="<?= e($v['landmark']) ?>">
  </div>

  <div class="card panel-card">
  <h2 class="pj-h">Contact</h2>
  <div class="row2">
    <div><label for="c-cp">Contact person</label><input id="c-cp" name="contact_person" maxlength="120" value="<?= e($v['contact_person']) ?>" required></div>
    <div><label for="c-pos">Position (optional)</label><input id="c-pos" name="contact_position" maxlength="80" value="<?= e($v['contact_position']) ?>"></div>
  </div>
  <div class="row2">
    <div><label for="c-ph">Contact mobile number</label><input id="c-ph" name="contact_phone" type="tel" value="<?= e($v['contact_phone']) ?>" required></div>
    <div><label for="c-fb">Facebook page (optional)</label><input id="c-fb" name="facebook" maxlength="255" value="<?= e($v['facebook']) ?>"></div>
  </div>
  </div>
  <div class="pj-actions"><button class="btn btn-primary" type="submit">Save changes</button></div>
</form>

<aside class="pj-side">
  <p class="side-note">How you appear to job seekers</p>
  <div class="card panel-card ep-me">
    <span class="av av-lg"><?php if ($p['logo_path']): ?><img src="<?= public_file_url($p['logo_path']) ?>" alt=""><?php else: ?><?= e(initials($p['employer_name'])) ?><?php endif; ?></span>
    <b><?= e($p['employer_name']) ?></b>
    <span class="vs <?= e($vClass) ?>"><?= $canPost ? icon('check', 12) : '' ?> <?= e($vLabel) ?></span>
    <p class="small"><?= e($typeLabel) ?></p>
  </div>
  <div class="card panel-card">
    <b>Tips for a strong profile</b>
    <ul class="ep-tips">
      <li>Add a clear logo or photo so people recognise you.</li>
      <li>Describe what you do in one or two sentences.</li>
      <li>Keep your mobile number active. Applicants may call.</li>
    </ul>
  </div>
</aside>
</div>
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>
