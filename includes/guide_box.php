<?php
// Getting-started box for brand-new users. It appears on the FIRST visit to the dashboard only.
// The "guide_seen" column in the users table remembers it (existing accounts are already marked as seen).

// True only the very first time it is called for this account, then marks the account as seen.
// If the guide_seen column does not exist yet, this quietly returns false so the dashboard still works.
function guide_first_visit(int $uid): bool {
    try {
        $s = db()->prepare('UPDATE users SET guide_seen = TRUE WHERE id = ? AND guide_seen = FALSE');
        $s->execute([$uid]);
        return $s->rowCount() > 0;
    } catch (Throwable $ex) {
        error_log('guide_seen check failed (did you run the SQL?): ' . $ex->getMessage());
        return false;
    }
}

// $steps = [[label, done?], ...]. $hash is '' (job seeker tab) or '#employers'.
function guide_box(string $heading, string $sub, array $steps, string $hash = ''): string {
    ob_start(); ?>
<section class="card panel-card" id="gs-box" style="margin-bottom:22px">
  <div class="panel-head"><div><h2 style="margin:0"><?= e($heading) ?></h2><p class="small" style="margin:4px 0 0"><?= e($sub) ?></p></div></div>
  <ul class="todo">
    <?php foreach ($steps as [$label, $ok]): ?>
      <li class="<?= $ok ? 'ok' : '' ?>"><span class="tick"><?= $ok ? icon('check', 14) : '' ?></span><?= e($label) ?></li>
    <?php endforeach; ?>
  </ul>
  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <a class="btn btn-primary btn-sm" href="<?= e(url('guide.php') . $hash) ?>">Read the full guide</a>
    <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('gs-box').remove()">Got it</button>
  </div>
  <p class="small" style="margin:12px 0 0">You will only see this once. You can open the guide any time from the menu.</p>
</section>
<?php
    return (string)ob_get_clean();
}
