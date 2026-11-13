<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/upload.php';
require_role('seeker');
$pdo = db(); $uid = (int)$_SESSION['uid'];
$barangays = $pdo->query('SELECT id, name FROM barangays WHERE is_active = 1 ORDER BY name')->fetchAll();
$opts = ['part_time' => 'Part-time', 'full_time' => 'Full-time', 'weekdays' => 'Weekdays', 'weekends' => 'Weekends', 'after_school' => 'After school'];

$s = $pdo->prepare('SELECT u.full_name, u.phone, u.photo_path, sp.barangay_id, sp.birth_date, sp.education, sp.skills, sp.work_experience, sp.availability
    FROM users u LEFT JOIN seeker_profiles sp ON sp.user_id = u.id WHERE u.id = ?');
$s->execute([$uid]);
$d = $s->fetch();
$errors = [];
$v = [
    'full_name' => $d['full_name'], 'phone' => $d['phone'] ?? '', 'barangay' => (string)($d['barangay_id'] ?? ''),
    'birth_date' => $d['birth_date'] ?? '', 'education' => $d['education'] ?? '', 'skills' => $d['skills'] ?? '',
    'work_experience' => $d['work_experience'] ?? '', 'avail' => $d['availability'] ? explode(',', $d['availability']) : [],
];
$selSkills = seeker_skill_list($pdo, $uid);   // skills chosen with the picker (old text profiles are converted on the fly)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) { $errors[] = 'The photo is too large. Please use one under 5 MB.'; }
    elseif (!csrf_check()) { $errors[] = 'Your session expired. Please try again.'; }
    else {
        foreach (['full_name', 'phone', 'barangay', 'birth_date', 'education', 'work_experience'] as $k) $v[$k] = trim((string)($_POST[$k] ?? ''));
        $v['phone'] = preg_replace('/[\s-]/', '', $v['phone']);
        $v['avail'] = array_values(array_intersect(array_keys($opts), (array)($_POST['avail'] ?? [])));

        if (mb_strlen($v['full_name']) < 2 || mb_strlen($v['full_name']) > 100) $errors[] = 'Enter your full name.';
        if (!preg_match('/^(09|\+639)\d{9}$/', $v['phone'])) $errors[] = 'Enter a valid mobile number, like 09123456789.';
        $bid = null;
        if ($v['barangay'] !== '') {
            foreach ($barangays as $b) if ((string)$b['id'] === $v['barangay']) $bid = (int)$b['id'];
            if ($bid === null) $errors[] = 'Choose a barangay from the list.';
        }
        $bd = null;
        if ($v['birth_date'] !== '') {
            $dt = DateTime::createFromFormat('Y-m-d', $v['birth_date']);
            if (!$dt || $dt->format('Y-m-d') !== $v['birth_date'] || $dt > new DateTime('today') || $dt < new DateTime('1930-01-01')) $errors[] = 'Enter a valid birth date.';
            else $bd = $v['birth_date'];
        }
        if (mb_strlen($v['education']) > 1500 || mb_strlen($v['work_experience']) > 1500) $errors[] = 'Education and work experience can each be up to 1500 characters.';
        $selSkills = skills_from_post($pdo, $errors, SKILL_MAX_SEEKER);
        // Keep the old comma-separated column in sync so older screens still show the skills
        $v['skills'] = mb_substr(implode(', ', array_column($selSkills, 'label')), 0, 500);

        $newPhoto = null;
        if (!$errors) {
            [$newPhoto, $perr] = save_profile_photo($_FILES['photo'] ?? []);
            if ($perr) $errors[] = $perr;
        }
        if (!$errors) {
            $oldPhoto = $d['photo_path'] ?? null;
            $photo = $oldPhoto;
            if ($newPhoto) $photo = $newPhoto;
            elseif (!empty($_POST['remove_photo'])) $photo = null;
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE users SET full_name = ?, phone = ?, photo_path = ? WHERE id = ?')->execute([$v['full_name'], $v['phone'], $photo, $uid]);
            // Applications keep a copy of the name and phone from the day you applied. Keep those copies current,
            // so employers always see your latest details.
            $pdo->prepare('UPDATE job_applications SET applicant_name = ?, applicant_phone = ? WHERE seeker_id = ?')->execute([$v['full_name'], $v['phone'], $uid]);
            $pdo->prepare('INSERT INTO seeker_profiles (user_id, barangay_id, birth_date, education, skills, work_experience, availability)
                VALUES (?,?,?,?,?,?,?)
                ON CONFLICT (user_id) DO UPDATE SET barangay_id = EXCLUDED.barangay_id, birth_date = EXCLUDED.birth_date, education = EXCLUDED.education,
                    skills = EXCLUDED.skills, work_experience = EXCLUDED.work_experience, availability = EXCLUDED.availability')
                ->execute([$uid, $bid, $bd, $v['education'] ?: null, $v['skills'] ?: null, $v['work_experience'] ?: null, $v['avail'] ? implode(',', $v['avail']) : null]);
            if (skills_ready($pdo)) skills_save($pdo, 'seeker_skills', $uid, $selSkills);
            $pdo->commit();
            if ($oldPhoto && $oldPhoto !== $photo) delete_public($oldPhoto);   // the old picture is no longer used anywhere
            if (skills_ready($pdo)) skills_auto_promote($pdo, $selSkills);
            $_SESSION['name'] = $v['full_name'];
            flash('success', 'Your profile was saved.');
            redirect('seeker/profile.php');
        } elseif ($newPhoto) delete_public($newPhoto);
    }
}
// Profile checklist: same six items as the Overview page, based on what is saved
$check = [
    ['Mobile number', !empty($d['phone'])], ['Barangay', !empty($d['barangay_id'])], ['Education', !empty($d['education'])],
    ['Skills', !empty($d['skills'])], ['Work experience', !empty($d['work_experience'])], ['Availability', !empty($d['availability'])],
];
$pct = (int)round(count(array_filter($check, fn($c) => $c[1])) / count($check) * 100);
$myBrgy = '';
foreach ($barangays as $b) if ((int)$b['id'] === (int)($d['barangay_id'] ?? 0)) $myBrgy = $b['name'];
$skillList  = array_slice(array_filter(array_map('trim', explode(',', (string)($d['skills'] ?? '')))), 0, 8);
$availSaved = $d['availability'] ? array_intersect(explode(',', $d['availability']), array_keys($opts)) : [];

$pageTitle = 'My Profile';
require __DIR__ . '/../includes/dash_top.php';
?>
<div class="profile">
<header class="pf-head">
  <div>
    <h1 class="dash-title pf-title">My profile</h1>
    <p class="pf-sub">When you apply for a job, that employer can see your photo, name, contact details, and the profile information below. Site admins can see it too. Nobody else can browse your profile.</p>
  </div>
  <span class="pf-pill"><b><?= $pct ?>%</b> complete</span>
</header>
<?php if ($errors): ?><div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="prf">
<form method="post" class="form prf-form" enctype="multipart/form-data" novalidate>
  <?= csrf_field() ?>
  <section class="card prf-sec">
    <h2 class="prf-h"><?= icon('user', 18) ?> Basic information</h2>
    <div class="logo-row prf-photo">
      <span class="av av-lg" id="photo-prev"><?= avatar_inner($d['photo_path'] ?? null, $d['full_name']) ?></span>
      <div style="flex:1;min-width:220px">
        <label for="p-photo" style="margin-top:0">Profile photo (JPG or PNG, up to 5 MB)</label>
        <input id="p-photo" name="photo" type="file" accept=".jpg,.jpeg,.png">
        <p class="small" style="margin:4px 0 0">A clear face photo helps employers recognise you. It is saved as a small square picture.</p>
        <?php if (!empty($d['photo_path'])): ?><label class="check-row"><input type="checkbox" name="remove_photo" value="1"> <span>Remove current photo</span></label><?php endif; ?>
      </div>
    </div>
    <div class="prf-grid">
      <div><label for="p-name">Full name</label><input id="p-name" name="full_name" maxlength="100" value="<?= e($v['full_name']) ?>" required></div>
      <div><label for="p-phone">Mobile number</label><input id="p-phone" name="phone" type="tel" placeholder="09123456789" value="<?= e($v['phone']) ?>" required></div>
      <div><label for="p-brgy">Barangay</label>
        <select id="p-brgy" name="barangay"><option value="">Choose barangay</option>
          <?php foreach ($barangays as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $v['barangay'] === (string)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div><label for="p-bd">Birth date (optional)</label><input id="p-bd" name="birth_date" type="date" max="<?= date('Y-m-d') ?>" value="<?= e($v['birth_date']) ?>"></div>
    </div>
  </section>

  <section class="card prf-sec">
    <h2 class="prf-h"><?= icon('file', 18) ?> About you</h2>
    <div class="prf-stack">
      <div><label for="p-edu">Education</label>
        <textarea id="p-edu" name="education" rows="3" maxlength="1500" placeholder="Example: Grade 12 graduate, Bunawan National High School"><?= e($v['education']) ?></textarea></div>
      <div><label id="p-skills-lbl">Skills</label>
        <p class="small" style="margin:0 0 4px">Pick what you can do. Employers and job matches use these, so choose the ones that fit you best.</p>
        <?php render_skill_picker($pdo, 'p-skills', $selSkills, SKILL_MAX_CUSTOM, SKILL_MAX_SEEKER); ?></div>
      <div><label for="p-exp">Work experience</label>
        <textarea id="p-exp" name="work_experience" rows="3" maxlength="1500" placeholder="Example: Store helper at ABC Store, 2023 to 2024"><?= e($v['work_experience']) ?></textarea></div>
    </div>
  </section>

  <section class="card prf-sec">
    <h2 class="prf-h" id="av-lbl"><?= icon('clock', 18) ?> I am available for</h2>
    <div class="chk-grid prf-chips" role="group" aria-labelledby="av-lbl">
      <?php foreach ($opts as $k => $lbl): ?>
        <label class="check-row chip-check"><input type="checkbox" name="avail[]" value="<?= e($k) ?>" <?= in_array($k, $v['avail'], true) ? 'checked' : '' ?>> <span><?= e($lbl) ?></span></label>
      <?php endforeach; ?>
    </div>
  </section>

  <div class="prf-save"><button class="btn btn-primary" type="submit">Save profile</button></div>
</form>

<aside class="prf-side">
  <div class="card prf-card">
    <div class="prf-who">
      <span class="avatar prf-av" aria-hidden="true"><?= avatar_inner($d['photo_path'] ?? null, $d['full_name']) ?></span>
      <div><b><?= e($d['full_name']) ?></b><span><?= e(implode(' · ', array_filter([$d['phone'] ?? '', $myBrgy]))) ?></span></div>
    </div>
    <div class="prf-strength"><span>Profile strength</span><b><?= $pct ?>%</b></div>
    <div class="bar prf-bar"><span style="width:<?= $pct ?>%"></span></div>
    <ul class="prf-check">
      <?php foreach ($check as [$lbl, $ok]): ?><li class="<?= $ok ? 'ok' : '' ?>"><span class="tick"><?= $ok ? icon('check', 12) : '' ?></span><?= e($lbl) ?></li><?php endforeach; ?>
    </ul>
    <p class="prf-tip"><?= $pct < 100 ? 'A few details are still missing.' : 'Your profile is complete. Great job.' ?></p>
  </div>
  <?php if ($skillList || $availSaved): ?>
  <div class="card prf-card">
    <?php if ($skillList): ?><h3 class="prf-h2">Skills</h3><div class="prf-tags"><?php foreach ($skillList as $sk): ?><span><?= e($sk) ?></span><?php endforeach; ?></div><?php endif; ?>
    <?php if ($availSaved): ?><h3 class="prf-h2">Available for</h3><div class="prf-tags prf-tags-g"><?php foreach ($availSaved as $av): ?><span><?= e($opts[$av]) ?></span><?php endforeach; ?></div><?php endif; ?>
  </div>
  <?php endif; ?>
</aside>
</div>
</div>
<script>
// Show the chosen photo right away, before saving
(function () {
  var inp = document.getElementById('p-photo'), box = document.getElementById('photo-prev');
  if (!inp || !box) return;
  inp.addEventListener('change', function () {
    var f = inp.files && inp.files[0];
    if (!f || !/^image\//.test(f.type)) return;
    var r = new FileReader();
    r.onload = function (e) { box.innerHTML = ''; var im = document.createElement('img'); im.src = e.target.result; im.alt = ''; box.appendChild(im); };
    r.readAsDataURL(f);
  });
})();
</script>
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>
