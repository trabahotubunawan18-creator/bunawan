<?php
require __DIR__ . '/../includes/admin_auth.php';
require __DIR__ . '/../includes/upload.php';
require_admin();
$pdo = db(); $adminId = (int)$_SESSION['uid'];
$id = (int)($_GET['id'] ?? 0);

$s = $pdo->prepare('SELECT ep.*, u.email, u.status AS user_status, u.created_at AS registered_at, b.name AS barangay
    FROM employer_profiles ep JOIN users u ON u.id = ep.user_id JOIN barangays b ON b.id = ep.barangay_id WHERE ep.user_id = ?');
$s->execute([$id]);
$p = $s->fetch();
if (!$p) { http_response_code(404); exit('Employer not found.'); }
$rs = $pdo->prepare('SELECT ref_name, ref_phone, relationship FROM employer_references WHERE employer_id = ? ORDER BY id');
$rs->execute([$id]);
$refs = $rs->fetchAll();

$status = $p['verification_status'];
$keys = ['permit_valid' => 'Permit or registration is valid and not expired', 'address_confirmed' => 'Address confirmed (exists in Bunawan)',
         'phone_called' => 'Contact number called and answered', 'id_matches' => 'ID is valid and matches the contact person, and the selfie shows the same person holding it', 'reference_called' => 'At least one reference called', 'facebook_checked' => 'Facebook page checked (if any)'];
$saved = json_decode($p['checklist_json'] ?? '', true) ?: [];
$checks = $saved['checks'] ?? []; $privNote = $saved['note'] ?? '';
$msg = ''; $peso = (int)$p['peso_endorsed']; $expiryIn = $p['verification_expires'] ?? '';
$errors = [];

$allowed = [
    'pending' => ['save', 'approve_business', 'approve_local', 'more_info', 'reject', 'suspend'],
    'more_info' => ['save', 'approve_business', 'approve_local', 'more_info', 'reject', 'suspend'],
    'rejected' => ['save', 'approve_business', 'approve_local', 'more_info', 'suspend'],
    'verified_business' => ['save', 'more_info', 'suspend'], 'verified_local' => ['save', 'more_info', 'suspend'],
    'suspended' => ['save', 'reactivate'],
][$status] ?? ['save'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    if (!csrf_check()) $errors[] = 'Your session expired. Please try again.';
    elseif (!in_array($act, $allowed, true)) $errors[] = 'That action is not available for this employer right now.';
    else {
        $checks = []; foreach ($keys as $k => $_) $checks[$k] = !empty($_POST['chk_' . $k]);
        $privNote = mb_substr(trim($_POST['private_note'] ?? ''), 0, 1000);
        $msg = mb_substr(trim($_POST['message'] ?? ''), 0, 1000);
        $peso = !empty($_POST['peso']) ? 1 : 0;
        $expiryIn = trim($_POST['expiry'] ?? '');
        $json = json_encode(['checks' => $checks, 'note' => $privNote]);

        $notify = function (string $text) use ($pdo, $id) {
            $pdo->prepare('INSERT INTO notifications (user_id, message, link) VALUES (?,?,?)')->execute([$id, $text, 'employer/verification.php']);
        };

        if ($act === 'save') {
            $pdo->prepare('UPDATE employer_profiles SET checklist_json = ?, peso_endorsed = ? WHERE user_id = ?')->execute([$json, $peso, $id]);
            log_admin('verification_checklist_saved', 'employer', $id);
            flash('success', 'Checklist and notes saved.'); redirect('admin/verify-review.php?id=' . $id);
        }
        if ($act === 'approve_business' || $act === 'approve_local') {
            $need = ['address_confirmed', 'phone_called', 'reference_called'];
            if ($p['id_doc_path'] && $p['selfie_path']) $need[] = 'id_matches'; // employers approved before the ID rule are not blocked
            if ($act === 'approve_business') {
                $need[] = 'permit_valid';
                if (!$p['permit_number']) $errors[] = 'A Verified Business needs a permit or registration number. Use Verified Local Employer instead.';
                if ($p['permit_expiry'] && $p['permit_expiry'] < date('Y-m-d')) $errors[] = 'The permit has expired, so this employer cannot be approved as a Verified Business.';
            }
            foreach ($need as $k) if (empty($checks[$k])) $errors[] = 'Tick the checklist item: ' . $keys[$k] . '.';
            // Expiry: default is 12 months, or the permit expiry date if that comes sooner
            $def = new DateTime('+12 months');
            if ($act === 'approve_business' && $p['permit_expiry'] && new DateTime($p['permit_expiry']) < $def) $def = new DateTime($p['permit_expiry']);
            $exp = $def->format('Y-m-d');
            if ($expiryIn !== '') {
                $d = DateTime::createFromFormat('Y-m-d', $expiryIn);
                if (!$d || $d->format('Y-m-d') !== $expiryIn || $d <= new DateTime('today') || $d > new DateTime('+25 months')) $errors[] = 'Choose a verification expiry date within the next 25 months.';
                else $exp = $expiryIn;
            }
            if (!$errors) {
                $new = $act === 'approve_business' ? 'verified_business' : 'verified_local';
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE employer_profiles SET verification_status = ?, verified_by = ?, verified_at = NOW(), verification_expires = ?,
                        peso_endorsed = ?, checklist_json = ?, verification_notes = NULL, rejection_reason = NULL WHERE user_id = ?')
                    ->execute([$new, $adminId, $exp, $peso, $json, $id]);
                $notify('Good news! Your verification was approved. You can now post jobs.');
                log_admin('employer_approved_' . ($act === 'approve_business' ? 'business' : 'local'), 'employer', $id, 'Valid until ' . $exp);
                $pdo->commit();
                flash('success', $p['employer_name'] . ' is now verified.'); redirect('admin/verification.php');
            }
        } elseif ($act === 'more_info' || $act === 'reject' || $act === 'suspend') {
            if (mb_strlen($msg) < 10) $errors[] = 'Write a message of at least 10 characters. The employer will see it' . ($act === 'suspend' ? '.' : ' and can use it to fix the problem.');
            if (!$errors) {
                $pdo->beginTransaction();
                if ($act === 'more_info') {
                    $pdo->prepare("UPDATE employer_profiles SET verification_status = 'more_info', verification_notes = ?, checklist_json = ?, peso_endorsed = ? WHERE user_id = ?")->execute([$msg, $json, $peso, $id]);
                    $notify('Our team needs more information for your verification. Please open Verification to see the note.');
                } elseif ($act === 'reject') {
                    $pdo->prepare("UPDATE employer_profiles SET verification_status = 'rejected', rejection_reason = ?, checklist_json = ?, peso_endorsed = 0, verified_by = NULL, verified_at = NULL WHERE user_id = ?")->execute([$msg, $json, $id]);
                    $notify('Your verification was not approved. Open Verification to see the reason.');
                } else {
                    $pdo->prepare("UPDATE employer_profiles SET verification_status = 'suspended', rejection_reason = ?, checklist_json = ?, peso_endorsed = 0 WHERE user_id = ?")->execute([$msg, $json, $id]);
                    $pdo->prepare("UPDATE users SET status = 'suspended' WHERE id = ? AND role = 'employer'")->execute([$id]);
                    $pdo->prepare("UPDATE job_posts SET status = 'closed' WHERE employer_id = ? AND status IN ('approved','pending')")->execute([$id]);
                }
                log_admin('employer_' . ($act === 'more_info' ? 'more_info_requested' : ($act === 'reject' ? 'rejected' : 'suspended')), 'employer', $id, $msg);
                $pdo->commit();
                flash('success', 'Done. The employer was ' . ($act === 'more_info' ? 'asked for more information.' : ($act === 'reject' ? 'rejected.' : 'suspended.')));
                redirect('admin/verification.php');
            }
        } elseif ($act === 'reactivate') {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ? AND role = 'employer'")->execute([$id]);
            $pdo->prepare("UPDATE employer_profiles SET verification_status = 'pending', rejection_reason = NULL WHERE user_id = ?")->execute([$id]);
            log_admin('employer_reactivated', 'employer', $id, 'Returned to pending review');
            $pdo->commit();
            flash('success', 'Account reactivated and returned to pending review.'); redirect('admin/verify-review.php?id=' . $id);
        }
    }
}

// ---- Possible duplicates: other employers that share the same details ----
$dups = [];
$nPhone = function ($x) { $d = preg_replace('/\D/', '', (string)$x); return strlen($d) >= 10 ? substr($d, -10) : $d; };
$nText  = fn($x) => preg_replace('/[^a-z0-9]+/', '', mb_strtolower((string)$x));
$nFb    = function ($u) {
    $u = trim((string)$u); if ($u === '') return '';
    $h = strtolower((string)parse_url($u, PHP_URL_HOST)); $h = preg_replace('/^(www|m|web|mobile)\./', '', $h);
    $path = rtrim(strtolower((string)parse_url($u, PHP_URL_PATH)), '/');
    parse_str((string)parse_url($u, PHP_URL_QUERY), $q);
    return $h . $path . (isset($q['id']) ? '?id=' . $q['id'] : '');
};
$mine = ['phone' => $nPhone($p['contact_phone']), 'addr' => $nText($p['full_address']), 'fb' => $nFb($p['facebook_url']),
         'permit' => $nText($p['permit_number']), 'dti' => $nText($p['dti_sec_number'])];
$myRefPhones = array_filter(array_map(fn($r) => $nPhone($r['ref_phone']), $refs));
$refByEmp = [];
$rr = $pdo->prepare('SELECT employer_id, ref_phone FROM employer_references WHERE employer_id <> ?'); $rr->execute([$id]);
foreach ($rr->fetchAll() as $r) $refByEmp[(int)$r['employer_id']][] = $nPhone($r['ref_phone']);
$os = $pdo->prepare('SELECT user_id, employer_name, verification_status, contact_phone, full_address, barangay_id, facebook_url, permit_number, dti_sec_number
    FROM employer_profiles WHERE user_id <> ?');
$os->execute([$id]);
foreach ($os->fetchAll() as $o) {
    $why = []; $oid = (int)$o['user_id']; $oPhone = $nPhone($o['contact_phone']);
    if ($mine['phone'] !== '' && $mine['phone'] === $oPhone) $why[] = 'Same contact number';
    if ($mine['phone'] !== '' && in_array($mine['phone'], $refByEmp[$oid] ?? [], true)) $why[] = 'This contact number is listed as one of their references';
    if ($oPhone !== '' && in_array($oPhone, $myRefPhones, true)) $why[] = 'A reference given here is their contact number';
    if (mb_strlen($mine['addr']) >= 5 && (int)$o['barangay_id'] === (int)$p['barangay_id'] && $mine['addr'] === $nText($o['full_address'])) $why[] = 'Same address';
    if ($mine['fb'] !== '' && $mine['fb'] === $nFb($o['facebook_url'])) $why[] = 'Same Facebook page';
    if ($mine['permit'] !== '' && $mine['permit'] === $nText($o['permit_number'])) $why[] = 'Same permit or registration number';
    if ($mine['dti'] !== '' && $mine['dti'] === $nText($o['dti_sec_number'])) $why[] = 'Same DTI or SEC number';
    if ($why) $dups[] = ['id' => $oid, 'name' => $o['employer_name'], 'status' => verif_info($o['verification_status'])[0], 'why' => $why];
}

$typeLabel = ['registered_business' => 'Registered business', 'organization' => 'Organization', 'individual' => 'Individual or household'][$p['employer_type']];
[$vLabel] = verif_info($status);
$stCls = ['pending' => 'st-pending', 'more_info' => 'st-pending', 'verified_business' => 'st-ok', 'verified_local' => 'st-ok', 'rejected' => 'st-bad', 'suspended' => 'st-bad'][$status];
$docs = [[$p['employer_type'] === 'organization' ? 'Registration or accreditation document' : 'Business permit', $p['permit_doc_path'], 'permit'], ['Barangay certification', $p['barangay_cert_path'], 'cert'], [$p['employer_type'] === 'organization' ? 'Office or place of operation' : 'Workplace photo', $p['workplace_photo_path'], 'photo'], ['Valid ID', $p['id_doc_path'], 'id'], ['Selfie with ID', $p['selfie_path'], 'selfie']];
$permitExpired = $p['permit_expiry'] && $p['permit_expiry'] < date('Y-m-d');
$mapUrl = 'https://www.google.com/maps/search/?api=1&query=' . urlencode($p['full_address'] . ', ' . $p['barangay'] . ', Bunawan, Agusan del Sur');
$can = fn($a) => in_array($a, $allowed, true);

$pageTitle = 'Review employer';
require __DIR__ . '/../includes/admin_top.php';
?>
<a class="back-link" href="<?= url('admin/verification.php') ?>">&larr; Back to queue</a>
<div class="rv-head"><div><h1 class="dash-title" style="margin:0"><?= e($p['employer_name']) ?></h1>
  <p class="dash-sub" style="margin:2px 0 0"><?= e($typeLabel) ?> &middot; registered <?= e(date('M j, Y', strtotime($p['registered_at']))) ?></p></div>
  <span class="st <?= $stCls ?>" style="font-size:14px"><?= e($vLabel) ?></span></div>

<?php if ($errors): ?><div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($dups): ?>
<div class="alert alert-info dup-alert" role="alert">
  <b>Possible duplicate<?= count($dups) === 1 ? '' : 's' ?> found.</b> Other employers use some of the same details. This is only a warning, so check before you decide.
  <ul class="dup-list">
    <?php foreach ($dups as $d): ?><li><a href="<?= url('admin/verify-review.php?id=' . $d['id']) ?>"><?= e($d['name']) ?></a> <span class="small">(<?= e($d['status']) ?>)</span>: <?= e(implode(', ', $d['why'])) ?></li><?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>
<?php if ($status === 'more_info' && $p['verification_notes']): ?><div class="alert alert-info"><b>Your earlier request:</b> <?= e($p['verification_notes']) ?></div><?php endif; ?>

<div class="rv-grid">
  <div>
    <div class="card panel-card">
      <div class="panel-head"><h2>Details</h2></div>
      <dl class="dl dl-wide">
        <dt>Contact person</dt><dd><?= e($p['contact_person']) ?><?= $p['contact_position'] ? ', ' . e($p['contact_position']) : '' ?></dd>
        <dt>Mobile number</dt><dd><a href="tel:<?= e($p['contact_phone']) ?>"><?= e($p['contact_phone']) ?></a> <span class="small">(call to confirm)</span></dd>
        <dt>Email</dt><dd><?= e($p['email']) ?></dd>
        <dt>Address</dt><dd><?= e($p['full_address']) ?>, <?= e($p['barangay']) ?><?= $p['landmark'] ? ' (near ' . e($p['landmark']) . ')' : '' ?> <a href="<?= e($mapUrl) ?>" target="_blank" rel="noopener noreferrer">View on map</a></dd>
        <?php if ($p['facebook_url']): ?><dt>Facebook</dt><dd><a href="<?= e($p['facebook_url']) ?>" target="_blank" rel="noopener noreferrer">Open page</a> <span class="small">(check age, activity, reviews)</span></dd><?php endif; ?>
        <?php if ($p['permit_number']): ?><dt><?= $p['employer_type'] === 'organization' ? 'Registration no.' : 'Permit no.' ?></dt><dd><?= e($p['permit_number']) ?></dd><?php endif; ?>
        <?php if ($p['permit_expiry']): ?><dt>Permit expiry</dt><dd><?= e(date('M j, Y', strtotime($p['permit_expiry']))) ?> <?= $permitExpired ? '<span class="st st-bad">Expired</span>' : '' ?></dd><?php endif; ?>
        <?php if ($p['dti_sec_number']): ?><dt>DTI or SEC no.</dt><dd><?= e($p['dti_sec_number']) ?></dd><?php endif; ?>
        <?php if ($p['description']): ?><dt>Description</dt><dd><?= nl2br(e($p['description'])) ?></dd><?php endif; ?>
        <dt>Agreed to terms</dt><dd><?= $p['agreed_terms_at'] ? e(date('M j, Y g:i A', strtotime($p['agreed_terms_at']))) : 'No' ?></dd>
      </dl>
    </div>

    <div class="card panel-card">
      <div class="panel-head"><h2>References</h2></div>
      <?php foreach ($refs as $r): ?><div class="row-item"><div><b><?= e($r['ref_name']) ?></b><div class="small"><?= e($r['relationship']) ?></div></div><a href="tel:<?= e($r['ref_phone']) ?>"><?= e($r['ref_phone']) ?></a></div><?php endforeach; ?>
      <?php if (!$refs): ?><p class="small">No references on file.</p><?php endif; ?>
    </div>

    <div class="card panel-card">
      <div class="panel-head"><h2>Documents</h2></div>
      <?php $any = false; foreach ($docs as [$label, $path, $key]): if (!$path) continue; $any = true; $isPdf = str_ends_with($path, '.pdf'); $src = url('admin/doc.php?id=' . $id . '&f=' . $key); ?>
        <div class="doc-box"><b><?= e($label) ?></b>
          <?php if ($isPdf): ?><iframe src="<?= e($src) ?>" title="<?= e($label) ?>" loading="lazy"></iframe><?php else: ?><a href="<?= e($src) ?>" target="_blank"><img src="<?= e($src) ?>" alt="<?= e($label) ?>" loading="lazy"></a><?php endif; ?>
          <a class="small" href="<?= e($src) ?>" target="_blank">Open in a new tab</a></div>
      <?php endforeach; if (!$any): ?><p class="small">No documents were uploaded.</p><?php endif; ?>
    </div>
  </div>

  <form method="post" class="card panel-card form decision" novalidate>
    <?= csrf_field() ?>
    <div class="panel-head"><h2>Decision</h2></div>
    <fieldset class="checks"><legend>Checklist</legend>
      <?php foreach ($keys as $k => $label): ?><label class="check-row"><input type="checkbox" name="chk_<?= $k ?>" value="1" <?= !empty($checks[$k]) ? 'checked' : '' ?>> <span><?= e($label) ?></span></label><?php endforeach; ?>
    </fieldset>
    <label for="d-note">Private notes (only admins see these)</label>
    <textarea id="d-note" name="private_note" rows="3" maxlength="1000"><?= e($privNote) ?></textarea>

    <label class="check-row"><input type="checkbox" name="peso" value="1" <?= $peso ? 'checked' : '' ?>> <span>PESO Bunawan endorsed</span></label>
    <?php if ($can('approve_business') || $can('approve_local')): ?>
      <label for="d-exp">Verification valid until (optional)</label>
      <input id="d-exp" type="date" name="expiry" value="<?= e($expiryIn) ?>">
      <p class="small" style="margin:4px 0 0">Leave empty for 12 months, or until the permit expires if that is sooner.</p>
    <?php endif; ?>

    <?php if ($can('more_info') || $can('reject') || $can('suspend')): ?>
      <label for="d-msg">Message to the employer (required for the three actions below)</label>
      <textarea id="d-msg" name="message" rows="3" maxlength="1000" placeholder="Explain what is missing, or why."><?= e($msg) ?></textarea>
    <?php endif; ?>

    <div class="dec-btns">
      <?php if ($can('approve_business')): ?><button class="btn btn-primary" name="action" value="approve_business" type="submit">Approve as Verified Business</button><?php endif; ?>
      <?php if ($can('approve_local')): ?><button class="btn btn-primary" name="action" value="approve_local" type="submit">Approve as Verified Local Employer</button><?php endif; ?>
      <?php if ($can('more_info')): ?><button class="btn btn-gold" name="action" value="more_info" type="submit"><?= in_array($status, ['verified_business', 'verified_local'], true) ? 'Request re-verification' : 'Request more info' ?></button><?php endif; ?>
      <?php if ($can('reject')): ?><button class="btn btn-danger" name="action" value="reject" type="submit" data-confirm="This employer will be rejected." data-confirm-title="Reject employer?" data-confirm-ok="Reject" data-confirm-tone="danger">Reject</button><?php endif; ?>
      <?php if ($can('suspend')): ?><button class="btn btn-danger" name="action" value="suspend" type="submit" data-confirm="This account will be suspended and their jobs will be closed." data-confirm-title="Suspend account?" data-confirm-ok="Suspend" data-confirm-tone="danger">Suspend account</button><?php endif; ?>
      <?php if ($can('reactivate')): ?><button class="btn btn-primary" name="action" value="reactivate" type="submit" data-confirm="This account will be reactivated and returned to pending review." data-confirm-title="Reactivate account?" data-confirm-ok="Reactivate" data-confirm-tone="info">Reactivate account</button><?php endif; ?>
      <button class="btn btn-outline" name="action" value="save" type="submit">Save checklist and notes</button>
    </div>
  </form>
</div>
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>