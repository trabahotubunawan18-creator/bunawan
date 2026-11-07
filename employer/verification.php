<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/upload.php';
require_role('employer');
$pdo = db(); $uid = (int)$_SESSION['uid'];

$s = $pdo->prepare('SELECT ep.*, b.name AS barangay FROM employer_profiles ep JOIN barangays b ON b.id = ep.barangay_id WHERE ep.user_id = ?');
$s->execute([$uid]);
$p = $s->fetch();
if (!$p) { http_response_code(404); exit('Employer profile not found.'); }
$st = $p['verification_status'];
[$vLabel, $vClass, $canPost] = verif_info($st);
$type = $p['employer_type'];
$canResubmit = in_array($st, ['more_info', 'rejected'], true);
$rs = $pdo->prepare('SELECT ref_name, ref_phone, relationship FROM employer_references WHERE employer_id = ? ORDER BY id LIMIT 2');
$rs->execute([$uid]);
$refs = $rs->fetchAll();
while (count($refs) < 2) $refs[] = ['ref_name' => '', 'ref_phone' => '', 'relationship' => ''];

$v = ['permit_number' => $p['permit_number'] ?? '', 'permit_expiry' => $p['permit_expiry'] ?? '', 'dti' => $p['dti_sec_number'] ?? ''];
foreach ([0, 1] as $i) { $v['r' . $i . 'n'] = $refs[$i]['ref_name']; $v['r' . $i . 'p'] = $refs[$i]['ref_phone']; $v['r' . $i . 'r'] = $refs[$i]['relationship']; }
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canResubmit) {
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) $errors[] = 'The files are too large. Please use files of 3 MB or less.';
    elseif (!csrf_check()) $errors[] = 'Your session expired. Please try again.';
    else {
        foreach ($v as $k => $_) $v[$k] = trim((string)($_POST[$k] ?? ''));
        $idfmt = '/^[A-Za-z0-9\-\/\. ]{3,60}$/u';
        if ($type !== 'individual' && !preg_match($idfmt, $v['permit_number'])) $errors[] = $type === 'organization' ? 'Enter your registration or accreditation number.' : 'Enter your Business Permit number.';
        if ($type === 'registered_business') {
            if ($v['dti'] === '') $errors[] = 'Enter your DTI or SEC registration number.';
            elseif (!preg_match($idfmt, $v['dti'])) $errors[] = 'The DTI or SEC number looks invalid.';
            $d = DateTime::createFromFormat('Y-m-d', $v['permit_expiry']);
            if (!$d || $d->format('Y-m-d') !== $v['permit_expiry']) $errors[] = 'Enter your permit expiry date.';
            elseif ($d < new DateTime('today')) $errors[] = 'Your permit has expired. Please renew it first.';
        }
        foreach ([0, 1] as $i) {
            $v['r' . $i . 'p'] = preg_replace('/[\s\-()]/', '', $v['r' . $i . 'p']);
            if (mb_strlen($v['r' . $i . 'n']) < 2 || mb_strlen($v['r' . $i . 'n']) > 120) $errors[] = 'Enter the name of reference ' . ($i + 1) . '.';
            if (!preg_match('/^\+?\d{7,13}$/', $v['r' . $i . 'p'])) $errors[] = 'Enter a valid phone number for reference ' . ($i + 1) . '.';
            if (mb_strlen($v['r' . $i . 'r']) < 2 || mb_strlen($v['r' . $i . 'r']) > 80) $errors[] = 'Tell us how reference ' . ($i + 1) . ' knows you.';
        }
        if ($v['r0p'] !== '' && $v['r0p'] === $v['r1p']) $errors[] = 'Please give two different references.';

        $saved = []; $new = ['permit_doc' => null, 'barangay_cert' => null, 'workplace_photo' => null, 'id_doc' => null, 'selfie' => null];
        if (!$errors) {
            foreach ($new as $f => $_) {
                [$rel, $err] = save_private_upload($_FILES[$f] ?? [], in_array($f, ['id_doc', 'selfie'], true) ? ID_TYPES : DOC_TYPES, 3 * 1048576, 'verification');
                if ($err) $errors[] = ucfirst(str_replace('_', ' ', $f)) . ': ' . $err;
                $new[$f] = $rel; if ($rel) $saved[] = $rel;
            }
            if (!$errors) {
                $has = fn($f, $col) => $new[$f] || !empty($p[$col]);
                if (!$has('workplace_photo', 'workplace_photo_path')) $errors[] = $type === 'organization' ? 'Please upload a photo of your office, signage, or the place where you operate.' : 'Please upload a photo of your workplace.';
                if ($type === 'registered_business' && !$has('permit_doc', 'permit_doc_path')) $errors[] = 'Please upload your Business Permit.';
                if ($type === 'organization' && !$has('permit_doc', 'permit_doc_path')) $errors[] = 'Please upload your registration or accreditation document.';
                if ($type === 'individual' && !$has('barangay_cert', 'barangay_cert_path')) $errors[] = 'Please upload your Barangay Certification.';
                if (!$has('id_doc', 'id_doc_path')) $errors[] = 'Please upload a photo of a valid government-issued ID of the contact person.';
                if (!$has('selfie', 'selfie_path')) $errors[] = 'Please upload a selfie of the contact person holding the same ID.';
            }
        }
        if (!$errors) {
            try {
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE employer_profiles SET permit_number = ?, permit_expiry = ?, dti_sec_number = ?, permit_doc_path = ?, barangay_cert_path = ?,
                        workplace_photo_path = ?, id_doc_path = ?, selfie_path = ?, verification_status = \'pending\' WHERE user_id = ?')
                    ->execute([$type === 'individual' ? null : $v['permit_number'], $type === 'registered_business' ? $v['permit_expiry'] : null,
                        $type === 'registered_business' ? $v['dti'] : ($p['dti_sec_number'] ?: null),
                        $new['permit_doc'] ?: $p['permit_doc_path'], $new['barangay_cert'] ?: $p['barangay_cert_path'],
                        $new['workplace_photo'] ?: $p['workplace_photo_path'], $new['id_doc'] ?: $p['id_doc_path'], $new['selfie'] ?: $p['selfie_path'], $uid]);
                $pdo->prepare('DELETE FROM employer_references WHERE employer_id = ?')->execute([$uid]);
                $ins = $pdo->prepare('INSERT INTO employer_references (employer_id, ref_name, ref_phone, relationship) VALUES (?,?,?,?)');
                foreach ([0, 1] as $i) $ins->execute([$uid, $v['r' . $i . 'n'], $v['r' . $i . 'p'], $v['r' . $i . 'r']]);
                $pdo->prepare("INSERT INTO notifications (user_id, message, link) SELECT id, CAST(? AS varchar), 'admin/verification.php' FROM users WHERE role = 'admin'")
                    ->execute([$p['employer_name'] . ' resubmitted their verification.']);
                $pdo->commit();
                // Remove replaced files only after the database update succeeded
                if ($new['permit_doc']) delete_private($p['permit_doc_path']);
                if ($new['barangay_cert']) delete_private($p['barangay_cert_path']);
                if ($new['workplace_photo']) delete_private($p['workplace_photo_path']);
                if ($new['id_doc']) delete_private($p['id_doc_path']);
                if ($new['selfie']) delete_private($p['selfie_path']);
                flash('success', 'Resubmitted. Our team will review it again.');
                redirect('employer/verification.php');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                foreach ($saved as $f) delete_private($f);
                error_log('Resubmit failed: ' . $e->getMessage());
                $errors[] = 'Something went wrong. Please try again.';
            }
        } else foreach ($saved as $f) delete_private($f);
    }
}

$msgs = [
    'pending' => 'Your application is under review. Our team will check your details, call your contact number and a reference, then decide.',
    'more_info' => 'Our team needs a little more information. Read the note below, then update your details and resubmit.',
    'verified_business' => 'Your business is verified. Your listings show a verified badge.',
    'verified_local' => 'You are verified as a local employer. Your listings show a verified badge.',
    'rejected' => 'Your verification was not approved. You may fix the problem and resubmit.',
    'suspended' => 'Your account is suspended. Please contact us.',
];
$cls = ['vs-ok' => 'vb-ok', 'vs-wait' => 'vb-pending', 'vs-bad' => 'vb-bad'][$vClass];
$stage = in_array($st, ['pending', 'more_info'], true) ? 2 : 3;
$docs = [[$type === 'organization' ? 'Registration or accreditation document' : 'Business Permit', $p['permit_doc_path'], 'permit'], ['Barangay Certification', $p['barangay_cert_path'], 'cert'], [$type === 'organization' ? 'Office or place of operation' : 'Workplace photo', $p['workplace_photo_path'], 'photo'], ['Valid ID', $p['id_doc_path'], 'id'], ['Selfie with ID', $p['selfie_path'], 'selfie']];

$pageTitle = 'Verification';
require __DIR__ . '/../includes/emp_top.php';
?>
<header class="ep-head">
  <div><h1 class="dash-title ep-title">Verification</h1><p class="ep-sub">Verified employers earn a badge and can post jobs.</p></div>
  <span class="ep-count"><?= e($vLabel) ?></span>
</header>
<?php if ($errors): ?><div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="vbanner <?= e($cls) ?>"><div style="flex:1">
  <h2 style="margin:0 0 4px;font-size:20px"><?= e($vLabel) ?></h2><p style="margin:0"><?= e($msgs[$st] ?? '') ?></p>
  <?php if ($st === 'more_info' && $p['verification_notes']): ?><p class="note-box"><b>Note from our team:</b> <?= nl2br(e($p['verification_notes'])) ?></p><?php endif; ?>
  <?php if ($st === 'rejected' && $p['rejection_reason']): ?><p class="note-box"><b>Reason:</b> <?= nl2br(e($p['rejection_reason'])) ?></p><?php endif; ?>
  <?php if ($canPost && $p['verification_expires']): ?><p class="small" style="margin:8px 0 0">Verification valid until <?= e(date('M j, Y', strtotime($p['verification_expires']))) ?>.</p><?php endif; ?>
</div></div>

<ol class="track" aria-label="Verification progress">
  <li class="done">Application submitted</li>
  <li class="<?= $stage === 2 ? 'now' : 'done' ?>">Under review</li>
  <li class="<?= $stage === 3 ? 'now' : '' ?>"><?= $stage === 3 ? e($vLabel) : 'Decision' ?></li>
</ol>

<div class="duo" style="margin-top:22px">
  <div class="card panel-card">
    <div class="panel-head"><h2>What you submitted</h2></div>
    <dl class="dl">
      <dt>Employer type</dt><dd><?= e(['registered_business' => 'Registered business', 'organization' => 'Organization', 'individual' => 'Individual or household'][$type]) ?></dd>
      <dt>Address</dt><dd><?= e($p['full_address']) ?>, <?= e($p['barangay']) ?></dd>
      <?php if ($p['permit_number']): ?><dt><?= $type === 'organization' ? 'Registration no.' : 'Permit no.' ?></dt><dd><?= e($p['permit_number']) ?><?= $p['permit_expiry'] ? ' (expires ' . e(date('M j, Y', strtotime($p['permit_expiry']))) . ')' : '' ?></dd><?php endif; ?>
      <?php if ($p['dti_sec_number']): ?><dt>DTI or SEC no.</dt><dd><?= e($p['dti_sec_number']) ?></dd><?php endif; ?>
      <dt>Contact</dt><dd><?= e($p['contact_person']) ?>, <?= e($p['contact_phone']) ?></dd>
      <?php foreach ($docs as [$label, $path, $key]): if ($path): ?><dt><?= e($label) ?></dt><dd><a href="<?= url('employer/doc.php?f=' . $key) ?>" target="_blank">View file</a></dd><?php endif; endforeach; ?>
    </dl>
  </div>
  <div class="card panel-card">
    <div class="panel-head"><h2>References</h2></div>
    <?php foreach ($refs as $r): if ($r['ref_name'] === '') continue; ?>
      <div class="row-item"><div><b><?= e($r['ref_name']) ?></b><div class="small"><?= e($r['relationship']) ?></div></div><span class="small"><?= e($r['ref_phone']) ?></span></div>
    <?php endforeach; ?>
    <p class="small" style="margin:12px 0 0">Our admin may call one of your references.</p>
  </div>
</div>

<?php if ($canResubmit): ?>
<form method="post" enctype="multipart/form-data" class="card panel-card form" style="margin-top:20px" novalidate>
  <?= csrf_field() ?>
  <h2 style="font-size:20px;margin:0">Update and resubmit</h2>
  <p class="small" style="margin:4px 0 0">Change only what our team asked for. Files must be JPG, PNG, or PDF (the ID and selfie must be JPG or PNG), up to 3 MB. Leave a file empty to keep the one you already uploaded.</p>
  <?php if ($type !== 'individual'): ?>
    <div class="row2">
      <div><label for="v-pn"><?= $type === 'organization' ? 'Registration or accreditation number' : "Business (Mayor's) Permit number" ?></label><input id="v-pn" name="permit_number" maxlength="60" value="<?= e($v['permit_number']) ?>" required></div>
      <?php if ($type === 'registered_business'): ?><div><label for="v-pe">Permit expiry date</label><input id="v-pe" name="permit_expiry" type="date" min="<?= date('Y-m-d') ?>" value="<?= e($v['permit_expiry']) ?>" required></div><?php endif; ?>
    </div>
    <?php if ($type === 'registered_business'): ?><label for="v-dti">DTI or SEC number</label><input id="v-dti" name="dti" maxlength="60" value="<?= e($v['dti']) ?>" required><?php endif; ?>
    <label for="v-pd"><?= $type === 'organization' ? 'New registration or accreditation document' : 'New permit document' ?><?= $p['permit_doc_path'] ? ' (optional)' : '' ?></label><input id="v-pd" name="permit_doc" type="file" accept=".jpg,.jpeg,.png,.pdf">
  <?php else: ?>
    <label for="v-bc">New Barangay Certification (optional)</label><input id="v-bc" name="barangay_cert" type="file" accept=".jpg,.jpeg,.png,.pdf">
  <?php endif; ?>
  <label for="v-ph"><?= $type === 'organization' ? 'New photo of your office, signage, or place of operation' : 'New workplace photo' ?><?= $p['workplace_photo_path'] ? ' (optional)' : '' ?></label><input id="v-ph" name="workplace_photo" type="file" accept=".jpg,.jpeg,.png">
  <label for="v-id">Valid ID of the contact person<?= $p['id_doc_path'] ? ' (optional, leave empty to keep your current one)' : '' ?></label><input id="v-id" name="id_doc" type="file" accept=".jpg,.jpeg,.png"<?= $p['id_doc_path'] ? '' : ' required' ?>>
  <label for="v-sf">Selfie holding the same ID<?= $p['selfie_path'] ? ' (optional, leave empty to keep your current one)' : '' ?></label><input id="v-sf" name="selfie" type="file" accept=".jpg,.jpeg,.png"<?= $p['selfie_path'] ? '' : ' required' ?>>
  <?php foreach ([0, 1] as $i): ?>
    <fieldset class="refset"><legend>Reference <?= $i + 1 ?></legend>
      <div class="row2">
        <div><label for="v-r<?= $i ?>n">Full name</label><input id="v-r<?= $i ?>n" name="r<?= $i ?>n" maxlength="120" value="<?= e($v['r' . $i . 'n']) ?>" required></div>
        <div><label for="v-r<?= $i ?>p">Phone number</label><input id="v-r<?= $i ?>p" name="r<?= $i ?>p" type="tel" value="<?= e($v['r' . $i . 'p']) ?>" required></div>
      </div>
      <label for="v-r<?= $i ?>r">How do they know you?</label><input id="v-r<?= $i ?>r" name="r<?= $i ?>r" maxlength="80" value="<?= e($v['r' . $i . 'r']) ?>" required>
    </fieldset>
  <?php endforeach; ?>
  <button class="btn btn-primary" type="submit">Resubmit for review</button>
</form>
<?php endif; ?>
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>