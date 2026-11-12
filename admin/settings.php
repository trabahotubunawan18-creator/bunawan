<?php
require __DIR__ . '/../includes/admin_auth.php';
require __DIR__ . '/../includes/upload.php';
require_admin();
$pdo = db();

function delete_qr(?string $rel): void {
    delete_public($rel);
}

function save_setting(PDO $pdo, string $key, string $value): void {
    $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value')->execute([$key, $value]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { flash('error', 'Your session expired. Please try again.'); redirect('admin/settings.php'); }
    $act = $_POST['action'] ?? '';
    try {
        if ($act === 'banner') {
            $text = mb_substr(trim($_POST['announcement'] ?? ''), 0, 250);
            save_setting($pdo, 'announcement', $text);
            save_setting($pdo, 'sample_notice', !empty($_POST['sample_notice']) ? '1' : '0');
            log_admin('settings_banner_updated', 'settings', null, $text !== '' ? 'Announcement set' : 'Announcement cleared');
            flash('success', 'Banner settings saved.');
        } elseif ($act === 'payment') {
            // Validate every field first, so nothing is half-saved if one is wrong.
            $new = []; $savedNow = [];
            foreach (PAYMENT_METHODS as $k => $lab) {
                $num = trim(preg_replace('/\s+/', ' ', (string)($_POST[$k . '_number'] ?? '')));
                $nm  = trim(preg_replace('/\s+/', ' ', (string)($_POST[$k . '_name'] ?? '')));
                $err = null;
                if ($num !== '' && !preg_match('/^[0-9 +\-]{7,24}$/', $num)) $err = $lab . ' number can only have digits, spaces, + and -.';
                elseif (mb_strlen($nm) > 80) $err = $lab . ' account name is too long (maximum 80 characters).';
                $qr = null;
                if (!$err && empty($_POST[$k . '_qr_remove'])) { [$qr, $uerr] = save_public_image($_FILES[$k . '_qr'] ?? [], 'qr', 2 * 1048576); if ($uerr) $err = $lab . ' QR: ' . $uerr; elseif ($qr) $savedNow[] = $qr; }
                if ($err) { foreach ($savedNow as $f) delete_qr($f); flash('error', $err); redirect('admin/settings.php#payment'); }
                $new[$k] = ['number' => $num, 'name' => $nm, 'qr' => $qr, 'remove' => !empty($_POST[$k . '_qr_remove'])];
            }
            foreach ($new as $k => $v) {
                save_setting($pdo, 'pay_' . $k . '_number', $v['number']);
                save_setting($pdo, 'pay_' . $k . '_name', $v['name']);
                if ($v['qr'] || $v['remove']) { delete_qr(setting('pay_' . $k . '_qr')); save_setting($pdo, 'pay_' . $k . '_qr', $v['qr'] ?? ''); }
            }
            log_admin('payment_accounts_updated', 'settings', null, 'GCash / MariBank details saved');
            flash('success', 'Payment accounts saved. Employers will see these when they subscribe to Premium.');
        } elseif ($act === 'keywords') {
            $words = preg_split('/[,\n\r]+/', mb_strtolower($_POST['keywords'] ?? ''));
            $words = array_values(array_unique(array_filter(array_map(fn($w) => mb_substr(trim($w), 0, 40), $words))));
            if (count($words) > 80) { flash('error', 'Too many keywords (maximum 80).'); redirect('admin/settings.php'); }
            save_setting($pdo, 'redflag_keywords', implode(',', $words));
            log_admin('settings_keywords_updated', 'settings', null, count($words) . ' keywords');
            flash('success', 'Red-flag keywords saved (' . count($words) . ').');
        } elseif ($act === 'add_barangay' || $act === 'rename_barangay') {
            $name = trim(preg_replace('/\s+/', ' ', $_POST['name'] ?? ''));
            if (mb_strlen($name) < 2 || mb_strlen($name) > 80) { flash('error', 'Barangay name must be 2 to 80 characters.'); redirect('admin/settings.php'); }
            if ($act === 'add_barangay') {
                $pdo->prepare('INSERT INTO barangays (name) VALUES (?)')->execute([$name]);
                log_admin('barangay_added', 'barangay', (int)$pdo->lastInsertId(), $name);
                flash('success', 'Barangay added.');
            } else {
                $id = (int)($_POST['id'] ?? 0);
                $pdo->prepare('UPDATE barangays SET name = ? WHERE id = ?')->execute([$name, $id]);
                log_admin('barangay_renamed', 'barangay', $id, $name);
                flash('success', 'Barangay renamed.');
            }
        } elseif ($act === 'toggle_barangay') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare('UPDATE barangays SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
            log_admin('barangay_toggled', 'barangay', $id);
            flash('success', 'Barangay updated.');
        }
    } catch (PDOException $ex) {
        flash('error', in_array($ex->getCode(), ['23000', '23505'], true) ? 'A barangay with that name already exists.' : 'Could not save. Please try again.');
    }
    redirect('admin/settings.php');
}

$announcement = setting('announcement');
$sample = setting('sample_notice', '1') === '1';
$keywords = implode(', ', array_filter(array_map('trim', explode(',', setting('redflag_keywords')))));
$brgs = $pdo->query("SELECT b.*, (SELECT COUNT(*) FROM job_posts j WHERE j.barangay_id = b.id) AS jobs FROM barangays b ORDER BY b.name")->fetchAll();

$kwList = array_values(array_filter(array_map('trim', explode(',', $keywords))));
$maxJobs = (int)max(array_merge([1], array_map('intval', array_column($brgs, 'jobs'))));
$brgOn = count(array_filter($brgs, fn($b) => (int)$b['is_active'] === 1));

$pageTitle = 'Settings';
require __DIR__ . '/../includes/admin_top.php';
?>
<header class="ad-hero">
  <div class="ad-hero-main"><h1 class="dash-title">Settings</h1>
  <p class="dash-sub">Site-wide options. Changes apply immediately.</p></div>
  <div class="ad-chips"><span class="ad-chip ad-chip--gold"><?= icon('bell', 16) ?> Banner <?= $announcement !== '' ? 'is live' : 'is off' ?></span><span class="ad-chip"><?= count($kwList) ?> keywords</span><span class="ad-chip"><?= $brgOn ?> barangays</span></div>
</header>

<div class="cf-grid">
  <form method="post" class="card cf-card form"><?= csrf_field() ?><input type="hidden" name="action" value="banner">
    <div class="cf-head"><span class="cf-tile cf-tile--gold"><?= icon('bell', 22) ?></span><div><h2>Announcement banner</h2><p>A short notice shown at the top of the public site.</p></div></div>
    <label class="cf-label" for="ann">Banner text <span>(leave empty to hide)</span></label>
    <textarea id="ann" name="announcement" rows="3" maxlength="250" placeholder="Example: The job fair is on Saturday at the municipal hall."><?= e($announcement) ?></textarea>
    <p class="cf-hint">Up to 250 characters.</p>
    <?php if ($announcement !== ''): ?><div class="cf-preview"><small>Currently showing</small><p><?= e($announcement) ?></p></div><?php endif; ?>
    <label class="cf-switch"><input type="checkbox" name="sample_notice" value="1" <?= $sample ? 'checked' : '' ?>><span class="cf-track" aria-hidden="true"></span><span class="cf-switch-text"><b>Show the "sample data" notice</b><span>Turn this off after real data is added.</span></span></label>
    <div class="cf-foot"><button class="btn btn-primary" type="submit">Save banner settings</button></div>
  </form>

  <form method="post" class="card cf-card form"><?= csrf_field() ?><input type="hidden" name="action" value="keywords">
    <div class="cf-head"><span class="cf-tile"><?= icon('flag', 22) ?></span><div><h2>Red-flag keywords</h2><p>Posts with these words are held for your review.</p></div></div>
    <label class="cf-label" for="kw">Keywords <span>(separate with commas or new lines)</span></label>
    <textarea id="kw" name="keywords" rows="6" placeholder="Example: fee, deposit, no experience needed"><?= e($keywords) ?></textarea>
    <p class="cf-hint">Held posts go to Flagged posts. Matching is case-insensitive and finds the word anywhere in the post.</p>
    <?php if ($kwList): ?><div class="cf-kws" aria-label="Current keywords"><?php foreach (array_slice($kwList, 0, 24) as $w): ?><span class="cf-kw"><?= e($w) ?></span><?php endforeach; ?><?php if (count($kwList) > 24): ?><span class="cf-kw cf-kw--more">+<?= count($kwList) - 24 ?> more</span><?php endif; ?></div><?php endif; ?>
    <div class="cf-foot"><button class="btn btn-primary" type="submit">Save keywords</button></div>
  </form>
</div>

<section class="card cf-card cf-wide" id="payment" aria-labelledby="payH" style="margin-bottom:20px">
  <div class="cf-head"><span class="cf-tile cf-tile--gold"><?= icon('star', 22) ?></span><div><h2 id="payH">Payment accounts</h2><p>Shown to employers when they subscribe to Premium. They can scan the QR or type the number. Use the QR from the "Receive money" screen of your app.</p></div></div>
  <form method="post" enctype="multipart/form-data" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="payment">
    <div class="pay-accts">
    <?php foreach (PAYMENT_METHODS as $k => $lab): $a = pay_account($k); ?>
      <fieldset class="pay-acct"><legend><?= e($lab) ?></legend>
        <label class="cf-label" for="<?= e($k) ?>_number">Number <span>(mobile number or account number)</span></label>
        <input id="<?= e($k) ?>_number" name="<?= e($k) ?>_number" maxlength="24" inputmode="tel" value="<?= e($a['number']) ?>" placeholder="09XX XXX XXXX">
        <label class="cf-label" for="<?= e($k) ?>_name" style="margin-top:12px!important">Account name <span>(employers see this to confirm they are paying the right person)</span></label>
        <input id="<?= e($k) ?>_name" name="<?= e($k) ?>_name" maxlength="80" value="<?= e($a['name']) ?>" placeholder="Name as shown in the app">
        <label class="cf-label" for="<?= e($k) ?>_qr" style="margin-top:12px!important">QR code image <span>(JPG or PNG, up to 2 MB)</span></label>
        <?php if ($a['qr']): ?>
          <div class="pay-qr-now"><img src="<?= e(public_file_url($a['qr'])) ?>" alt="<?= e($lab) ?> QR code" width="120" height="120">
            <label class="check-row"><input type="checkbox" name="<?= e($k) ?>_qr_remove" value="1"> <span>Remove this QR</span></label></div>
          <p class="cf-hint" style="margin:0 0 6px">Choose a new file below to replace it.</p>
        <?php endif; ?>
        <input type="file" id="<?= e($k) ?>_qr" name="<?= e($k) ?>_qr" accept="image/png,image/jpeg">
      </fieldset>
    <?php endforeach; ?>
    </div>
    <div class="cf-foot"><button class="btn btn-primary" type="submit">Save payment accounts</button></div>
  </form>
</section>

<section class="card cf-card cf-wide" aria-labelledby="brgH">
  <div class="cf-head"><span class="cf-tile cf-tile--gold"><?= icon('map', 22) ?></span><div><h2 id="brgH">Barangays</h2><p>Check this list against the official list of Bunawan. Barangays cannot be deleted because jobs and profiles use them; disable one to hide it from forms.</p></div></div>
  <form method="post" class="cf-add form"><?= csrf_field() ?><input type="hidden" name="action" value="add_barangay">
    <span class="cf-add-ico"><?= icon('plus', 20) ?></span>
    <div class="cf-add-text"><b>Add a new barangay</b><span>Name must be 2 to 80 characters.</span></div>
    <input name="name" maxlength="80" placeholder="New barangay name" required aria-label="New barangay name">
    <button class="btn btn-primary" type="submit"><?= icon('plus', 16) ?> Add barangay</button></form>
  <div class="cf-table"><div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Barangay</th><th>Jobs</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($brgs as $b): ?>
      <tr><td><form method="post" class="form cf-name"><?= csrf_field() ?><input type="hidden" name="action" value="rename_barangay"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
          <span class="cf-tile cf-tile--sm"><?= icon('pin', 17) ?></span>
          <input name="name" maxlength="80" value="<?= e($b['name']) ?>" aria-label="Barangay name" required><button class="btn btn-outline btn-sm" type="submit">Rename</button></form></td>
        <td class="cf-jobs"><b><?= (int)$b['jobs'] ?></b> <span>job<?= (int)$b['jobs'] === 1 ? '' : 's' ?></span><span class="cf-meter"><i style="width:<?= (int)round((int)$b['jobs'] / $maxJobs * 100) ?>%"></i></span></td>
        <td><span class="st <?= $b['is_active'] ? 'st-ok' : 'st-off' ?>"><?= $b['is_active'] ? 'Enabled' : 'Disabled' ?></span></td>
        <td><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_barangay"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
          <button class="btn <?= $b['is_active'] ? 'btn-outline' : 'btn-primary' ?> btn-sm"><?= $b['is_active'] ? 'Disable' : 'Enable' ?></button></form></td></tr>
    <?php endforeach; ?></tbody></table></div></div>
</section>
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>
