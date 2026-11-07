<?php
require __DIR__ . '/../includes/admin_auth.php';
require_admin();
$pdo = db();

if (!skills_ready($pdo)) {
    $pageTitle = 'Skills';
    require __DIR__ . '/../includes/admin_top.php';
    echo '<div class="card empty"><h3>Skills tables are missing</h3><p>Run <b>migrations/001_skills.sql</b> in phpMyAdmin (database trabaho_tu_bunawan), then reload this page.</p></div>';
    require __DIR__ . '/../includes/dash_bottom.php';
    exit;
}
skills_catalog($pdo);   // seeds the starter list the first time

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { flash('error', 'Your session expired. Please try again.'); redirect('admin/skills.php'); }
    $act = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $name = trim(preg_replace('/\s+/u', ' ', (string)($_POST['name'] ?? '')));
    try {
        if ($act === 'add') {
            $key = skill_key($name);
            if (mb_strlen($name) < 2 || mb_strlen($name) > 60 || $key === '') { flash('error', 'Skill name must be 2 to 60 characters.'); redirect('admin/skills.php'); }
            $pdo->prepare('INSERT INTO skills (name, skill_key) VALUES (?, ?)')->execute([skill_label($name), $key]);
            $sid = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO skill_aliases (skill_id, alias_key) VALUES (?, ?) ON CONFLICT DO NOTHING');
            foreach (preg_split('/[,;\n]+/', (string)($_POST['aliases'] ?? '')) as $al) { $ak = skill_key($al); if ($ak !== '' && $ak !== $key) { $ins->execute([$sid, $ak]); skill_merge_typed($pdo, $ak); } }
            // Anything people already typed that matches this skill now counts as it
            skill_merge_typed($pdo, $key);
            log_admin('skill_added', 'skill', $sid, $name);
            flash('success', 'Skill added.');
        } elseif ($act === 'rename') {
            if (mb_strlen($name) < 2 || mb_strlen($name) > 60) { flash('error', 'Skill name must be 2 to 60 characters.'); redirect('admin/skills.php'); }
            $pdo->prepare('UPDATE skills SET name = ? WHERE id = ?')->execute([skill_label($name), $id]);
            $pdo->prepare('UPDATE seeker_skills ss SET label = s.name FROM skills s WHERE s.skill_key = ss.skill_key AND s.id = ?')->execute([$id]);
            $pdo->prepare('UPDATE job_skills js SET label = s.name FROM skills s WHERE s.skill_key = js.skill_key AND s.id = ?')->execute([$id]);
            log_admin('skill_renamed', 'skill', $id, $name);
            flash('success', 'Skill renamed. Matching is not affected.');
        } elseif ($act === 'toggle') {
            $pdo->prepare('UPDATE skills SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
            log_admin('skill_toggled', 'skill', $id);
            flash('success', 'Skill updated.');
        } elseif ($act === 'alias_add') {
            $ak = skill_key((string)($_POST['alias'] ?? ''));
            $sk = $pdo->prepare('SELECT skill_key FROM skills WHERE id = ?'); $sk->execute([$id]);
            $own = (string)$sk->fetchColumn();
            if ($ak === '' || $ak === $own) { flash('error', 'Enter a different word or phrase.'); redirect('admin/skills.php'); }
            $pdo->prepare('INSERT INTO skill_aliases (skill_id, alias_key) VALUES (?, ?)')->execute([$id, $ak]);
            skill_merge_typed($pdo, $ak);
            log_admin('skill_alias_added', 'skill', $id, (string)($_POST['alias'] ?? ''));
            flash('success', 'Synonym added.');
        } elseif ($act === 'alias_del') {
            $pdo->prepare('DELETE FROM skill_aliases WHERE id = ?')->execute([(int)($_POST['alias_id'] ?? 0)]);
            log_admin('skill_alias_removed', 'skill', $id);
            flash('success', 'Synonym removed.');
        } elseif ($act === 'promote') {
            $k = (string)($_POST['key'] ?? ''); $lbl = skill_label((string)($_POST['label'] ?? ''));
            if ($k !== '' && $lbl !== '') { skill_promote($pdo, $k, $lbl); log_admin('skill_promoted', 'skill', null, $lbl); flash('success', 'Added to the fixed skill list.'); }
        } elseif ($act === 'merge') {
            $from = (string)($_POST['from'] ?? ''); $to = (string)($_POST['to'] ?? '');
            if ($from !== '' && $to !== '') { skill_merge($pdo, $from, $to); log_admin('skill_merged', 'skill', null, $from . ' -> ' . $to); flash('success', 'Merged. Everyone who typed it now matches the fixed skill.'); }
        }
    } catch (PDOException $ex) {
        flash('error', in_array($ex->getCode(), ['23000', '23505'], true) ? 'That skill or synonym already exists.' : 'Could not save the change.');
    }
    redirect('admin/skills.php');
}

$rows = $pdo->query("SELECT s.*, (SELECT COUNT(*) FROM seeker_skills x WHERE x.skill_key = s.skill_key) AS n_seekers,
    (SELECT COUNT(*) FROM job_skills x WHERE x.skill_key = s.skill_key) AS n_jobs FROM skills s ORDER BY s.name")->fetchAll();
$aliases = [];
foreach ($pdo->query('SELECT id, skill_id, alias_key FROM skill_aliases ORDER BY alias_key') as $a) $aliases[(int)$a['skill_id']][] = $a;
$typed = $pdo->query("SELECT t.skill_key AS k, MIN(t.label) AS label, SUM(CASE WHEN t.src = 's' THEN 1 ELSE 0 END) AS seekers, SUM(CASE WHEN t.src = 'j' THEN 1 ELSE 0 END) AS jobs
    FROM (SELECT skill_key, label, 's' AS src FROM seeker_skills WHERE is_custom = 1 UNION ALL SELECT skill_key, label, 'j' AS src FROM job_skills WHERE is_custom = 1) t
    GROUP BY t.skill_key ORDER BY (SUM(CASE WHEN t.src = 's' THEN 1 ELSE 0 END) + SUM(CASE WHEN t.src = 'j' THEN 1 ELSE 0 END)) DESC, label LIMIT 50")->fetchAll();
$fixed = array_values(array_filter($rows, fn($r) => $r['is_active']));

$pageTitle = 'Skills';
require __DIR__ . '/../includes/admin_top.php';
?>
<div class="sk-page">
<header class="ad-hero ad-hero--compact">
  <div class="ad-hero-main"><h1 class="dash-title">Skills</h1>
  <p class="dash-sub">The skill list job seekers and employers pick from. Add skills and local-language synonyms here.</p></div>
  <div class="ad-chips"><span class="ad-chip ad-chip--gold"><?= icon('star', 16) ?> <?= count($rows) ?> skills</span><span class="ad-chip"><?= count($fixed) ?> active</span></div>
</header>

<form method="post" class="card panel-card form sk-card sk-add-card"><?= csrf_field() ?><input type="hidden" name="action" value="add">
  <h2 class="sk-h">Add a skill</h2>
  <div class="sk-add-grid">
    <div><label for="sk-name">Skill name</label><input class="sk-input" id="sk-name" name="name" maxlength="60" required placeholder="Example: Tricycle driving"></div>
    <div><label for="sk-al">Synonyms <span class="sk-opt">(optional, separate with commas)</span></label><input class="sk-input" id="sk-al" name="aliases" maxlength="300" placeholder="Example: drayber, tricycle driver"></div>
    <button class="btn btn-primary sk-add-btn" type="submit">Add skill</button>
  </div>
</form>

<?php if ($typed): ?>
<div class="card panel-card sk-card">
  <h2 class="sk-h">Skills people typed themselves <span class="sk-badge"><?= count($typed) ?></span></h2>
  <p class="sk-help">These already work for matching when two people type the same thing. Skills typed by <?= (int)SKILL_AUTO_PROMOTE ?> different seekers join the fixed list on their own. You can also add one now, or merge a spelling into an existing skill.</p>
  <div class="tbl-wrap"><table class="table sk-table"><thead><tr><th>Typed skill</th><th class="sk-c">Seekers</th><th class="sk-c">Jobs</th><th>Actions</th></tr></thead><tbody>
  <?php foreach ($typed as $t): ?>
    <tr><td><b class="sk-name"><?= e($t['label']) ?></b></td><td class="sk-c"><span class="sk-num"><?= (int)$t['seekers'] ?></span></td><td class="sk-c"><span class="sk-num"><?= (int)$t['jobs'] ?></span></td>
      <td><div class="sk-actions">
        <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="promote"><input type="hidden" name="key" value="<?= e($t['k']) ?>"><input type="hidden" name="label" value="<?= e($t['label']) ?>">
          <button class="btn btn-outline btn-sm" type="submit">Add to fixed list</button></form>
        <form method="post" class="form sk-inline"><?= csrf_field() ?><input type="hidden" name="action" value="merge"><input type="hidden" name="from" value="<?= e($t['k']) ?>">
          <select class="sk-input" name="to" aria-label="Merge into" required><option value="">Merge into...</option>
            <?php foreach ($fixed as $f): ?><option value="<?= e($f['skill_key']) ?>"><?= e($f['name']) ?></option><?php endforeach; ?></select>
          <button class="btn btn-outline btn-sm" type="submit">Merge</button></form>
      </div></td></tr>
  <?php endforeach; ?></tbody></table></div>
</div>
<?php endif; ?>

<div class="card panel-card sk-card">
  <h2 class="sk-h">Fixed skills and synonyms <span class="sk-badge"><?= count($rows) ?></span></h2>
  <div class="tbl-wrap"><table class="table sk-table sk-fixed"><thead><tr><th>Skill</th><th>Synonyms</th><th class="sk-c">Seekers</th><th class="sk-c">Jobs</th><th class="sk-c">Status</th></tr></thead><tbody>
  <?php foreach ($rows as $s): ?>
    <tr class="<?= $s['is_active'] ? '' : 'is-off' ?>">
      <td class="sk-col-skill"><form method="post" class="form sk-inline"><?= csrf_field() ?><input type="hidden" name="action" value="rename"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
        <input class="sk-input" name="name" maxlength="60" value="<?= e($s['name']) ?>" aria-label="Skill name" required><button class="btn btn-outline btn-sm" type="submit">Rename</button></form></td>
      <td class="sk-col-syn">
        <?php if (!empty($aliases[(int)$s['id']])): ?><div class="sk-chips">
          <?php foreach ($aliases[(int)$s['id']] as $a): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="alias_del"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="alias_id" value="<?= (int)$a['id'] ?>">
              <span class="sk-chip"><?= e($a['alias_key']) ?> <button type="submit" class="sk-x" aria-label="Remove synonym <?= e($a['alias_key']) ?>">&times;</button></span></form>
          <?php endforeach; ?>
        </div><?php endif; ?>
        <form method="post" class="form sk-inline"><?= csrf_field() ?><input type="hidden" name="action" value="alias_add"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <input class="sk-input" name="alias" maxlength="60" placeholder="Add a synonym" aria-label="Add a synonym" required><button class="btn btn-outline btn-sm" type="submit">Add</button></form></td>
      <td class="sk-c"><span class="sk-num<?= (int)$s['n_seekers'] ? ' has' : '' ?>"><?= (int)$s['n_seekers'] ?></span></td>
      <td class="sk-c"><span class="sk-num<?= (int)$s['n_jobs'] ? ' has' : '' ?>"><?= (int)$s['n_jobs'] ?></span></td>
      <td class="sk-c"><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
        <button class="btn <?= $s['is_active'] ? 'btn-outline' : 'btn-primary' ?> btn-sm sk-toggle" name="action" value="toggle"><?= $s['is_active'] ? 'Disable' : 'Enable' ?></button></form></td></tr>
  <?php endforeach; ?></tbody></table></div>
  <p class="sk-help sk-foot">Synonyms are shown in their matching form (lowercase, simplified). Renaming a skill never changes how it matches. Disabling hides a skill from new pickers; existing matches are kept.</p>
</div>
</div><!-- /.sk-page -->
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>