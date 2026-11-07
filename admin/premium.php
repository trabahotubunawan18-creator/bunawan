<?php
require __DIR__ . '/../includes/admin_auth.php';
require_admin();
$pdo = db(); $adminId = (int)$_SESSION['uid'];

// Subscriptions past their end date become expired
$pdo->exec("UPDATE premium_subscriptions SET status = 'expired' WHERE status = 'active' AND ends_on < CURRENT_DATE");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { flash('error', 'Your session expired. Please try again.'); redirect('admin/premium.php'); }
    $act = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $s = $pdo->prepare('SELECT ps.*, ep.verification_status FROM premium_subscriptions ps JOIN employer_profiles ep ON ep.user_id = ps.employer_id WHERE ps.id = ?');
    $s->execute([$id]);
    $sub = $s->fetch();
    $notify = fn(string $t) => $pdo->prepare('INSERT INTO notifications (user_id, message, link) VALUES (?,?,?)')->execute([$sub['employer_id'], $t, 'employer/premium.php']);

    if (!$sub) {
        flash('error', 'Subscription not found.');
    } elseif ($act === 'activate' && $sub['status'] === 'requested') {
        $months = in_array((int)($_POST['months'] ?? 1), [1, 3, 6, 12], true) ? (int)$_POST['months'] : 1;
        $a = $pdo->prepare('SELECT COUNT(*) FROM premium_subscription_jobs WHERE subscription_id = ?');
        $a->execute([$id]);
        $nPosts = (int)$a->fetchColumn();
        if (!verif_info($sub['verification_status'])[2]) flash('error', 'Only verified employers can have Premium.');
        elseif ($nPosts < 1) flash('error', 'This request has no job posts left (they may have been deleted). Decline it.');
        elseif (empty($sub['payment_ref'])) flash('error', 'The employer has not sent a payment reference number yet. Wait for it, then check your GCash or MariBank app.');
        elseif (empty($_POST['received'])) flash('error', 'Tick the box to confirm you saw this payment in your ' . (PAYMENT_METHODS[$sub['payment_method']] ?? 'payment') . ' app.');
        else {
            $amount = PREMIUM_PRICE * $nPosts * $months;   // P49 x posts x months
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE premium_subscriptions SET status = 'active', amount = ?, post_count = ?, starts_on = CURRENT_DATE, ends_on = (CURRENT_DATE + (CAST(? AS integer) * INTERVAL '1 month')), is_simulated = 0, activated_by = ? WHERE id = ?")->execute([$amount, $nPosts, $months, $adminId, $id]);
            $notify('Your Premium is now active for ' . $nPosts . ' post' . ($nPosts > 1 ? 's' : '') . ', for ' . $months . ' month' . ($months > 1 ? 's' : '') . '.');
            log_admin('premium_activated', 'employer', (int)$sub['employer_id'], $nPosts . ' post(s), ' . $months . ' month(s), ref ' . $sub['payment_ref']);
            $pdo->commit();
            flash('success', 'Premium activated for ' . $nPosts . ' post' . ($nPosts > 1 ? 's' : '') . ', ' . $months . ' month' . ($months > 1 ? 's' : '') . '.');
        }
    } elseif ($act === 'cancel' && in_array($sub['status'], ['requested', 'active'], true)) {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE premium_subscriptions SET status = 'cancelled' WHERE id = ?")->execute([$id]);
        $notify($sub['status'] === 'requested' ? 'Your Premium request was declined.' : 'Your Premium subscription was ended by the admin.');
        log_admin($sub['status'] === 'requested' ? 'premium_declined' : 'premium_deactivated', 'employer', (int)$sub['employer_id']);
        $pdo->commit();
        flash('success', $sub['status'] === 'requested' ? 'Request declined.' : 'Premium deactivated.');
    }
    redirect('admin/premium.php?tab=' . urlencode($_GET['tab'] ?? 'requested'));
}

$tabs = ['requested' => 'Requests', 'active' => 'Active', 'expired' => 'Expired', 'cancelled' => 'Cancelled'];
$tab = array_key_exists($_GET['tab'] ?? '', $tabs) ? $_GET['tab'] : 'requested';
$tabCount = [];
foreach ($tabs as $k => $_) { $c = $pdo->prepare('SELECT COUNT(*) FROM premium_subscriptions WHERE status = ?'); $c->execute([$k]); $tabCount[$k] = (int)$c->fetchColumn(); }
$r = $pdo->prepare('SELECT ps.*, ep.employer_name, ep.verification_status FROM premium_subscriptions ps JOIN employer_profiles ep ON ep.user_id = ps.employer_id
    WHERE ps.status = ? ORDER BY ps.created_at DESC LIMIT 50');
$r->execute([$tab]);
$rows = $r->fetchAll();
$subJobs = [];
if ($rows) {
    $in = implode(',', array_map('intval', array_column($rows, 'id')));
    foreach ($pdo->query("SELECT x.subscription_id, j.title FROM premium_subscription_jobs x JOIN job_posts j ON j.id = x.job_id WHERE x.subscription_id IN ($in) ORDER BY j.title")->fetchAll() as $jr)
        $subJobs[(int)$jr['subscription_id']][] = $jr['title'];
}
$st = $pdo->query("SELECT COALESCE(SUM(CASE WHEN status = 'requested' THEN 1 ELSE 0 END), 0) AS req, COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) AS act,
    COALESCE(SUM(CASE WHEN status = 'active' AND ends_on <= (CURRENT_DATE + INTERVAL '7 days') THEN 1 ELSE 0 END), 0) AS soon,
    COALESCE(SUM(CASE WHEN status = 'active' THEN amount END), 0) AS val FROM premium_subscriptions")->fetch();
$emptyMsg = ['requested' => ['No pending requests', 'When a verified employer picks posts to boost, the request will show up here with the posts and payment method.'],
    'active' => ['No active subscriptions', 'Activate a request and it will be listed here with its remaining days.'],
    'expired' => ['Nothing has expired yet', 'Subscriptions that reach their end date move here automatically.'],
    'cancelled' => ['Nothing cancelled', 'Declined requests and deactivated subscriptions appear here.']];
$stLabel = ['requested' => ['Awaiting review', 'st-pending'], 'active' => ['Active', 'st-ok'], 'expired' => ['Expired', 'st-off'], 'cancelled' => ['Cancelled', 'st-bad']];

// ---------- Export: Print, Save as PDF, Save as CSV (every subscription in the current tab) ----------
require_once __DIR__ . '/../includes/admin_export.php';
$xr = $pdo->prepare("SELECT ps.id, ps.amount, ps.payment_method, ps.status, ps.starts_on, ps.ends_on, ps.created_at, ep.employer_name,
        (SELECT STRING_AGG(j.title, '; ' ORDER BY j.title) FROM premium_subscription_jobs x JOIN job_posts j ON j.id = x.job_id WHERE x.subscription_id = ps.id) AS job_titles
    FROM premium_subscriptions ps JOIN employer_profiles ep ON ep.user_id = ps.employer_id WHERE ps.status = ? ORDER BY ps.created_at DESC LIMIT " . XP_MAX_ROWS);
$xr->execute([$tab]);
$xpRows = []; $xn = 0; $xSum = 0.0;
foreach ($xr as $x) {
    $xpRows[] = [++$xn, pay_code((int)$x['id']), $x['employer_name'], $x['job_titles'] ?: 'None', PAYMENT_METHODS[$x['payment_method']] ?? 'Not set',
        $stLabel[$x['status']][0] ?? $x['status'], date('M j, Y', strtotime($x['created_at'])),
        $x['starts_on'] ? date('M j, Y', strtotime($x['starts_on'])) : 'N/A', $x['ends_on'] ? date('M j, Y', strtotime($x['ends_on'])) : 'N/A', number_format((float)$x['amount'], 2)];
    $xSum += (float)$x['amount'];
}
$xp = ['title' => 'Premium Subscriptions Report', 'slug' => 'premium',
    'purpose' => 'Premium (featured job) requests and subscriptions with payment method, dates and amount in pesos.',
    'filters' => ['Status' => $tabs[$tab]],
    'head' => ['No.', 'Payment code', 'Employer', 'Job posts', 'Payment method', 'Status', 'Date requested', 'Starts', 'Ends', 'Amount (PHP)'],
    'rows' => $xpRows, 'right' => [9], 'landscape' => true,
    'total' => ['', 'Total', '', '', '', '', '', '', '', number_format($xSum, 2)]];

$pageTitle = 'Premium';
require __DIR__ . '/../includes/admin_top.php';
?>
<header class="ad-hero ad-hero--compact">
  <div class="ad-hero-main"><h1 class="dash-title">Premium</h1>
  <p class="dash-sub">₱49 per month for each job post. Check the payment in your GCash or MariBank app, then activate.</p></div>
  <div class="ad-chips"><span class="ad-chip ad-chip--gold"><?= icon('star', 16) ?> <?= (int)$st['req'] ?> pending request<?= (int)$st['req'] === 1 ? '' : 's' ?></span><span class="ad-chip"><?= (int)$st['act'] ?> active</span></div>
</header>


<div class="pm-stats">
  <div class="pm-stat pm-stat--gold<?= (int)$st['req'] > 0 ? ' pm-stat--hot' : '' ?>"><span class="pm-ico"><?= icon('clock', 22) ?></span><div><strong><?= (int)$st['req'] ?></strong><span>Waiting for review</span></div></div>
  <div class="pm-stat"><span class="pm-ico"><?= icon('star', 22) ?></span><div><strong><?= (int)$st['act'] ?></strong><span>Employers with active Premium</span></div></div>
  <div class="pm-stat pm-stat--gold"><span class="pm-ico"><?= icon('calendar', 22) ?></span><div><strong><?= (int)$st['soon'] ?></strong><span>Ending within 7 days</span></div></div>
  <div class="pm-stat"><span class="pm-ico"><?= icon('chart', 22) ?></span><div><strong>&#8369;<?= number_format((float)$st['val'], 2) ?></strong><span>Value of active plans</span></div></div>
</div>

<div class="filter-tabs pm-tabs"><?php foreach ($tabs as $k => $l): ?><a class="<?= $tab === $k ? 'on' : '' ?>" href="<?= url('admin/premium.php?tab=' . $k) ?>"><?= e($l) ?> <span class="ad-count"><?= $tabCount[$k] ?></span></a><?php endforeach; ?></div>

<?php if (!$rows): ?>
  <div class="card empty ad-empty"><div class="ad-empty-ico"><?= icon('star', 28) ?></div><h3><?= e($emptyMsg[$tab][0]) ?></h3><p><?= e($emptyMsg[$tab][1]) ?></p></div>
<?php else: ?>
  <div class="pm-grid pm-grid--<?= e($tab) ?>">
  <?php foreach ($rows as $x):
    $v = verif_info($x['verification_status']); $isVerified = (bool)$v[2];
    [$sl, $sc] = $stLabel[$x['status']] ?? ['Unknown', 'st-off'];
    $left = $pct = $span = 0;
    if ($x['starts_on'] && $x['ends_on']) {
        $span = max(1, (int)round((strtotime($x['ends_on']) - strtotime($x['starts_on'])) / 86400));
        $left = max(0, (int)ceil((strtotime($x['ends_on']) - strtotime(date('Y-m-d'))) / 86400));
        $pct = max(0, min(100, (int)round(($span - $left) / $span * 100)));
    } ?>
    <article class="pm-card pm-card--<?= e($x['status']) ?>">
      <div class="pm-main">
      <div class="pm-head">
        <span class="pm-av" aria-hidden="true"><?= e(initials($x['employer_name'])) ?></span>
        <div class="pm-id"><h3 title="<?= e($x['employer_name']) ?>"><?= e($x['employer_name']) ?></h3>
          <span class="pm-ver <?= $isVerified ? 'ok' : 'no' ?>"><?= icon($isVerified ? 'shield' : 'clock', 14) ?> <?= e($v[0]) ?></span></div>
        <?php if ($x['status'] === 'active'): ?><span class="pm-feat"><?= icon('star', 13) ?> Featured</span><?php else: ?><span class="st <?= $sc ?>"><?= e($sl) ?></span><?php endif; ?>
      </div>

      <div class="pm-price"><div><small>Amount<?= $x['status'] === 'requested' ? ' (1 month)' : '' ?></small><strong>&#8369;<?= number_format((float)$x['amount'], 2) ?></strong></div>
        <span class="st st-pending"><?= e(PAYMENT_METHODS[$x['payment_method']] ?? 'No method') ?></span></div>
      <?php $titles = $subJobs[(int)$x['id']] ?? []; ?>
      <p class="pm-posts"><b><?= count($titles) ?> post<?= count($titles) === 1 ? '' : 's' ?>:</b> <?= $titles ? e(implode(' · ', $titles)) : 'none left' ?></p>

      <dl class="pm-meta">
        <div><dt>Requested</dt><dd><?= e(date('M j, Y', strtotime($x['created_at']))) ?></dd></div>
        <div><dt>Period</dt><dd><?= $x['starts_on'] ? e(date('M j', strtotime($x['starts_on'])) . ' - ' . date('M j, Y', strtotime($x['ends_on']))) : '&mdash;' ?></dd></div>
      </dl>

      <?php if ($x['status'] === 'active'): ?>
        <div class="pm-life"><div class="pm-life-top"><span>Time used</span><b class="<?= $left <= 7 ? 'pm-soon' : '' ?>"><?= $left ?> day<?= $left === 1 ? '' : 's' ?> left</b></div>
          <span class="pm-meter" role="img" aria-label="<?= $pct ?> percent of the subscription used"><i style="width:<?= $pct ?>%"></i></span></div>
      <?php endif; ?>
      </div><!-- /.pm-main -->

      <?php if ($x['status'] === 'requested' || $x['status'] === 'active'):
        $mName = PAYMENT_METHODS[$x['payment_method']] ?? 'payment';
        $base = PREMIUM_PRICE * max(1, count($titles));
        $hasPaid = $x['paid_amount'] !== null;
        $guess = $hasPaid ? (int)round((float)$x['paid_amount'] / $base) : 1;
        $exact = $hasPaid && in_array($guess, [1, 3, 6, 12], true) && abs((float)$x['paid_amount'] - $guess * $base) < 0.01;
        $selMonths = $exact ? $guess : 1;
      ?>
      <div class="pm-side">
        <div class="pm-paybox<?= $x['payment_ref'] ? '' : ' pm-paybox--none' ?>">
          <?php if ($x['payment_ref']): ?>
            <b>Payment details from the employer</b>
            <dl class="pm-meta">
              <div><dt>Reference no.</dt><dd><code><?= e($x['payment_ref']) ?></code></dd></div>
              <div><dt>Amount paid</dt><dd>&#8369;<?= number_format((float)$x['paid_amount'], 2) ?></dd></div>
              <div><dt>Paid from</dt><dd><?= e($x['payer_name']) ?></dd></div>
              <div><dt>Sent</dt><dd><?= $x['paid_at'] ? e(date('M j, Y g:i A', strtotime($x['paid_at']))) : '&mdash;' ?></dd></div>
            </dl>
            <?php if ($x['proof_path']): ?><p><a href="<?= e(url('admin/pay-proof.php?id=' . (int)$x['id'])) ?>" target="_blank" rel="noopener">View screenshot</a> (a screenshot can be faked, so always check your app)</p><?php endif; ?>
            <?php if ($x['status'] === 'requested'): ?>
              <p class="pm-note"><?= icon('shield', 16) ?> <span>Open your <?= e($mName) ?> app &rarr; transaction history. The reference number, amount and sender must match. Code in the message should be <b><?= e(pay_code((int)$x['id'])) ?></b>.</span></p>
              <?php if (!$exact): ?><p class="pm-note pm-note--warn"><span>Paid &#8369;<?= number_format((float)$x['paid_amount'], 2) ?>, but 1, 3, 6 or 12 months of <?= count($titles) ?> post<?= count($titles) === 1 ? '' : 's' ?> costs &#8369;<?= number_format($base) ?>, &#8369;<?= number_format($base * 3) ?>, &#8369;<?= number_format($base * 6) ?> or &#8369;<?= number_format($base * 12) ?>. Check the amount before activating.</span></p><?php endif; ?>
            <?php endif; ?>
          <?php else: ?>
            <p class="pm-note"><span>Waiting for the employer to send the payment reference. Their payment code is <b><?= e(pay_code((int)$x['id'])) ?></b>.</span></p>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($x['status'] === 'requested' && !$isVerified): ?><p class="pm-note"><?= icon('shield', 16) ?> <span>This employer is not verified yet, so Premium cannot be activated.</span></p><?php endif; ?>

      <?php if ($x['status'] === 'requested' || $x['status'] === 'active'): ?>
        <form method="post" action="<?= url('admin/premium.php?tab=' . $tab) ?>" class="form pm-actions"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$x['id'] ?>">
          <?php if ($x['status'] === 'requested'): ?>
            <label class="pm-check"><input type="checkbox" name="received" value="1" <?= $x['payment_ref'] ? 'required' : 'disabled' ?>> <span>I checked my <?= e($mName) ?> app and received this payment.</span></label>
            <select name="months" aria-label="Duration"><?php foreach ([1 => '1 month', 3 => '3 months', 6 => '6 months', 12 => '12 months'] as $mv => $ml): ?><option value="<?= $mv ?>" <?= $mv === $selMonths ? 'selected' : '' ?>><?= $ml ?></option><?php endforeach; ?></select>
            <button class="btn btn-primary btn-sm" name="action" value="activate" <?= ($isVerified && $x['payment_ref']) ? '' : 'disabled' ?>>Activate</button>
            <button class="btn btn-outline btn-sm" name="action" value="cancel" formnovalidate data-confirm="This Premium request will be declined." data-confirm-title="Decline request?" data-confirm-ok="Decline" data-confirm-tone="danger">Decline</button>
          <?php else: ?>
            <button class="btn btn-danger btn-sm" name="action" value="cancel" data-confirm="This Premium subscription will end now." data-confirm-title="End Premium?" data-confirm-ok="Deactivate" data-confirm-tone="danger">Deactivate</button>
          <?php endif; ?>
        </form>
      </div><!-- /.pm-side -->
      <?php else: ?>
        <p class="pm-done"><?= $x['status'] === 'expired' ? 'This subscription reached its end date.' : 'This request or subscription was cancelled.' ?></p>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>