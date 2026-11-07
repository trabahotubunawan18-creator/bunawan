<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/upload.php';
require_role('seeker');
$pdo = db(); $uid = (int)$_SESSION['uid'];
$errors = [];

$cur = $pdo->prepare('SELECT resume_path FROM seeker_profiles WHERE user_id = ?');
$cur->execute([$uid]);
$resume = $cur->fetchColumn() ?: null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) $errors[] = 'That file is too large. The maximum is 2 MB.';
    elseif (!csrf_check()) $errors[] = 'Your session expired. Please try again.';
    elseif (($_POST['action'] ?? '') === 'upload') {
        [$rel, $err] = save_private_upload($_FILES['resume'] ?? [], RESUME_TYPES, 2 * 1048576, 'resumes');
        if ($err) $errors[] = $err;
        elseif (!$rel) $errors[] = 'Please choose a PDF or DOCX file.';
        else {
            $pdo->prepare('INSERT INTO seeker_profiles (user_id, resume_path) VALUES (?, ?) ON CONFLICT (user_id) DO UPDATE SET resume_path = EXCLUDED.resume_path')->execute([$uid, $rel]);
            delete_private($resume);
            flash('success', 'Your resume was uploaded.');
            redirect('seeker/resume.php');
        }
    } elseif (($_POST['action'] ?? '') === 'delete' && $resume) {
        $pdo->prepare('UPDATE seeker_profiles SET resume_path = NULL WHERE user_id = ?')->execute([$uid]);
        delete_private($resume);
        flash('success', 'Your resume was deleted.');
        redirect('seeker/resume.php');
    }
}
$pageTitle = 'Resume';
require __DIR__ . '/../includes/dash_top.php';
$ext = $resume ? strtoupper(pathinfo($resume, PATHINFO_EXTENSION)) : '';
?>
<div class="resume">
<header class="rs-head">
  <div>
    <h1 class="dash-title rs-title">Resume</h1>
    <p class="rs-sub">Optional. Most local jobs do not need one. Upload it only if you want to attach it when an employer asks.</p>
  </div>
  <?php if ($resume): ?><span class="rs-pill rs-pill-ok"><?= icon('check', 14) ?> <b><?= e($ext) ?></b> resume uploaded</span>
  <?php else: ?><span class="rs-pill">No resume yet</span><?php endif; ?>
</header>
<?php if ($errors): ?><div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="rs-grid">
  <div class="rs-main">
    <?php if ($resume): ?>
      <div class="card rs-current">
        <span class="rs-file" aria-hidden="true"><?= icon('file', 22) ?><em><?= e($ext) ?></em></span>
        <div class="rs-info"><b>Your resume is uploaded</b><div class="small">Only you and the employers you apply to can see it.</div></div>
        <div class="row-actions">
          <a class="btn btn-outline btn-sm" href="<?= url('seeker/resume-download.php') ?>">Download</a>
          <form method="post" data-confirm="Your resume will be deleted." data-confirm-title="Delete your resume?" data-confirm-ok="Delete" data-confirm-tone="danger"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="btn btn-del btn-sm" type="submit">Delete</button></form>
        </div>
      </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="card form rs-upload">
      <?= csrf_field() ?><input type="hidden" name="action" value="upload">
      <h2 class="rs-h"><?= $resume ? 'Replace your resume' : 'Upload a resume' ?></h2>
      <p class="small rs-note">PDF or DOCX, up to 2 MB.</p>
      <label class="rs-drop" for="rs-file">
        <span class="rs-drop-ico"><?= icon('plus', 22) ?></span>
        <span class="rs-drop-text"><b>Choose a file</b> or drop it here</span>
        <span class="rs-drop-name" id="rs-name">No file chosen</span>
        <input id="rs-file" name="resume" type="file" accept=".pdf,.docx" required>
      </label>
      <button class="btn btn-primary" type="submit"><?= $resume ? 'Replace resume' : 'Upload resume' ?></button>
    </form>
  </div>

  <aside class="card rs-tips">
    <h2 class="rs-h">Quick tips</h2>
    <ul>
      <li><?= icon('check', 14) ?> Keep it to one or two pages.</li>
      <li><?= icon('check', 14) ?> Add your mobile number and skills.</li>
      <li><?= icon('check', 14) ?> PDF looks the same on every phone.</li>
      <li><?= icon('lock', 14) ?> Delete it any time from this page.</li>
    </ul>
  </aside>
</div>
</div>
<script>
(function () {
  var i = document.getElementById('rs-file'), n = document.getElementById('rs-name');
  if (!i || !n) return;
  var z = i.closest('.rs-drop');
  i.addEventListener('change', function () { n.textContent = i.files && i.files[0] ? i.files[0].name : 'No file chosen'; z.classList.toggle('has-file', !!(i.files && i.files[0])); });
  ['dragenter', 'dragover'].forEach(function (t) { z.addEventListener(t, function () { z.classList.add('drag'); }); });
  ['dragleave', 'drop'].forEach(function (t) { z.addEventListener(t, function () { z.classList.remove('drag'); }); });
})();
</script>
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>