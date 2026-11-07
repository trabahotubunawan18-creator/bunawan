<?php
require __DIR__ . '/../includes/admin_auth.php';
require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { flash('error', 'Your session expired. Please try again.'); redirect('admin/categories.php'); }
    $act = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $name = trim(preg_replace('/\s+/', ' ', $_POST['name'] ?? ''));
    try {
        if ($act === 'add' || $act === 'rename') {
            if (mb_strlen($name) < 2 || mb_strlen($name) > 80) { flash('error', 'Category name must be 2 to 80 characters.'); redirect('admin/categories.php'); }
            if ($act === 'add') {
                $next = (int)$pdo->query('SELECT COALESCE(MAX(sort_order),0) + 1 FROM job_categories')->fetchColumn();
                $pdo->prepare('INSERT INTO job_categories (name, sort_order) VALUES (?, ?)')->execute([$name, $next]);
                log_admin('category_added', 'category', (int)$pdo->lastInsertId(), $name);
                flash('success', 'Category added.');
            } else {
                $pdo->prepare('UPDATE job_categories SET name = ? WHERE id = ?')->execute([$name, $id]);
                log_admin('category_renamed', 'category', $id, $name);
                flash('success', 'Category renamed.');
            }
        } elseif ($act === 'toggle') {
            $pdo->prepare('UPDATE job_categories SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
            log_admin('category_toggled', 'category', $id);
            flash('success', 'Category updated.');
        } elseif ($act === 'up' || $act === 'down') {
            $ids = $pdo->query('SELECT id FROM job_categories ORDER BY sort_order, id')->fetchAll(PDO::FETCH_COLUMN);
            $i = array_search($id, array_map('intval', $ids), true);
            $j = $act === 'up' ? $i - 1 : $i + 1;
            if ($i !== false && isset($ids[$j])) {
                [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
                $pdo->beginTransaction();
                $u = $pdo->prepare('UPDATE job_categories SET sort_order = ? WHERE id = ?');
                foreach ($ids as $n => $cid) $u->execute([$n + 1, $cid]);
                log_admin('category_reordered', 'category', $id);
                $pdo->commit();
            }
        }
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', in_array($ex->getCode(), ['23000', '23505'], true) ? 'A category with that name already exists.' : 'Could not save the category.');
    }
    redirect('admin/categories.php');
}

$rows = $pdo->query("SELECT c.*, (SELECT COUNT(*) FROM job_posts j WHERE j.category_id = c.id AND j.status = 'approved' AND j.deadline >= CURRENT_DATE) AS active_jobs,
    (SELECT COUNT(*) FROM job_posts j WHERE j.category_id = c.id) AS all_jobs FROM job_categories c ORDER BY c.sort_order, c.id")->fetchAll();

$catTotal = count($rows); $catOn = 0;
foreach ($rows as $c0) if ($c0['is_active']) $catOn++;
$catOff = $catTotal - $catOn;

$pageTitle = 'Categories';
require __DIR__ . '/../includes/admin_top.php';
?>
<link rel="stylesheet" href="<?= e(url('assets/css/admin-categories.css')) ?>">
<div class="cg-page">
<div class="cg-hero">
  <div><h1>Categories</h1><p>Categories cannot be deleted because jobs use them. Disable one to hide it from new posts and filters.</p></div>
  <div class="cg-hero-chips">
    <span class="cg-chip cg-chip-gold"><?= icon('tag', 16) ?><?= number_format($catTotal) ?> <?= $catTotal === 1 ? 'category' : 'categories' ?></span>
    <span class="cg-chip cg-chip-glass"><?= number_format($catOn) ?> enabled<?= $catOff > 0 ? ' &middot; ' . number_format($catOff) . ' disabled' : '' ?></span>
  </div>
</div>

<form method="post" class="cg-add"><?= csrf_field() ?><input type="hidden" name="action" value="add">
  <div class="cg-add-head"><span class="cg-add-ico" aria-hidden="true"><?= icon('plus', 20) ?></span><div><b>Add a new category</b><small>Name must be 2 to 80 characters.</small></div></div>
  <div class="cg-add-row"><input class="cg-input" name="name" maxlength="80" placeholder="New category name" required aria-label="New category name">
    <button class="btn btn-primary" type="submit"><?= icon('plus', 16) ?>Add category</button></div>
</form>

<div class="cg-results"><span><b><?= number_format($catTotal) ?></b> <?= $catTotal === 1 ? 'category' : 'categories' ?> in your list</span><span class="cg-tip">Use the arrows to change the order of the list.</span></div>

<div class="cg-card"><table class="cg-table">
  <thead><tr><th>Order</th><th>Category</th><th>Active jobs</th><th>Status</th><th>Actions</th></tr></thead><tbody>
  <?php foreach ($rows as $n => $x): $jobsOn = (int)$x['active_jobs']; $all = (int)$x['all_jobs']; $pct = $all > 0 ? (int)round($jobsOn / $all * 100) : 0; ?>
    <tr class="<?= $x['is_active'] ? '' : 'is-off' ?>">
      <td class="c-order"><div class="cg-order"><span class="cg-rank" aria-label="Position <?= $n + 1 ?>"><?= $n + 1 ?></span>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$x['id'] ?>">
          <button class="cg-mv" name="action" value="up" <?= $n === 0 ? 'disabled' : '' ?> aria-label="Move up"><svg class="ico" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg></button>
          <button class="cg-mv" name="action" value="down" <?= $n === count($rows) - 1 ? 'disabled' : '' ?> aria-label="Move down"><svg class="ico" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M19 12l-7 7-7-7"/></svg></button></form></div></td>
      <td class="c-name"><div class="cg-name"><span class="cg-tile" aria-hidden="true"><?= icon('tag', 18) ?></span>
        <form method="post" class="cg-rename"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"><input type="hidden" name="action" value="rename">
          <input class="cg-input" name="name" maxlength="80" value="<?= e($x['name']) ?>" aria-label="Category name" required><button class="btn btn-outline btn-sm" type="submit">Rename</button></form></div></td>
      <td class="c-jobs"><div class="cg-jobs"><b><?= $jobsOn ?></b><small>of <?= $all ?> total</small><div class="cg-bar" aria-hidden="true"><span style="width:<?= $pct ?>%"></span></div></div></td>
      <td class="c-status"><span class="cg-status <?= $x['is_active'] ? 'cg-on' : 'cg-off' ?>"><?= $x['is_active'] ? 'Enabled' : 'Disabled' ?></span></td>
      <td class="c-act"><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$x['id'] ?>">
        <button class="btn <?= $x['is_active'] ? 'btn-outline' : 'btn-primary' ?> btn-sm cg-act" name="action" value="toggle"><?= $x['is_active'] ? 'Disable' : 'Enable' ?></button></form></td></tr>
  <?php endforeach; ?></tbody></table></div>
</div>
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>