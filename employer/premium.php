<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/redflags.php';
require __DIR__ . '/../includes/upload.php';
require_role('employer');
$pdo = db(); $uid = (int)$_SESSION['uid'];

$s = $pdo->prepare('SELECT employer_name, verification_status FROM employer_profiles WHERE user_id = ?');
$s->execute([$uid]);
$p = $s->fetch();
if (!$p) { http_response_code(404); exit('Employer profile not found.'); }
$canPost = verif_info($p['verification_status'])[2];

$pdo->exec("UPDATE premium_subscriptions SET status = 'expired' WHERE status = 'active' AND ends_on < CURRENT_DATE");

// Job posts this employer can still boost: live or waiting for review, not already in a pending or active Premium
function premium_eligible_jobs(PDO $pdo, int $uid): array {
    $q = $pdo->prepare("SELECT j.id, j.title, j.status, j.deadline FROM job_posts j
        WHERE j.employer_id = ? AND j.status IN ('approved','pending') AND j.deadline >= CURRENT_DATE
          AND NOT EXISTS (SELECT 1 FROM premium_subscription_jobs x JOIN premium_subscriptions sb ON sb.id = x.subscription_id
                          WHERE x.job_id = j.id AND sb.status IN ('requested','active'))
        ORDER BY j.created_at DESC");
    $q->execute([$uid]);
    return $q->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    if (!csrf_check()) flash('error', 'Your session expired. Please try again.');
    elseif ($act === 'request') {
        $ids = array_values(array_unique(array_map('intval', (array)($_POST['job_ids'] ?? []))));
        $method = (string)($_POST['payment_method'] ?? '');
        $okIds = array_map('intval', array_column(premium_eligible_jobs($pdo, $uid), 'id'));
        if (!$canPost) flash('error', 'You can request Premium once your account is verified.');
        elseif (!$ids) flash('error', 'Choose at least one job post to boost.');
        elseif (!isset(PAYMENT_METHODS[$method])) flash('error', 'Please choose a payment method.');
        elseif (array_diff($ids, $okIds)) flash('error', 'One of the posts you chose is not available for Premium. Please try again.');
        else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO premium_subscriptions (employer_id, amount, payment_method, post_count) VALUES (?,?,?,?)')
                    ->execute([$uid, PREMIUM_PRICE * count($ids), $method, count($ids)]);
                $sid = (int)$pdo->lastInsertId();
                $ins = $pdo->prepare('INSERT INTO premium_subscription_jobs (subscription_id, job_id) VALUES (?,?)');
                foreach ($ids as $jid) $ins->execute([$sid, $jid]);
                $pdo->prepare("INSERT INTO notifications (user_id, message, link) SELECT id, CAST(? AS varchar), 'admin/premium.php' FROM users WHERE role = 'admin' AND status = 'active'")
                    ->execute(['Premium request from ' . mb_substr($p['employer_name'], 0, 80) . ': ' . count($ids) . ' post' . (count($ids) > 1 ? 's' : '') . ', P' . (PREMIUM_PRICE * count($ids)) . ' via ' . PAYMENT_METHODS[$method]]);
                $pdo->commit();
                $_SESSION['open_pay'] = $sid;
                flash('success', 'Request sent for ' . count($ids) . ' post' . (count($ids) > 1 ? 's' : '') . ' (₱' . number_format(PREMIUM_PRICE * count($ids)) . '). Send your payment using the details in the window, then enter your reference number.');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Premium request failed: ' . $e->getMessage());
                flash('error', 'Something went wrong. Please try again.');
            }
        }
    } elseif ($act === 'submit_payment') {
        $sid = (int)($_POST['sub_id'] ?? 0);
        $q = $pdo->prepare("SELECT * FROM premium_subscriptions WHERE id = ? AND employer_id = ? AND status = 'requested'");
        $q->execute([$sid, $uid]);
        $sub = $q->fetch();
        $ref   = strtoupper(preg_replace('/\s+/', '', (string)($_POST['payment_ref'] ?? '')));
        $payer = trim(preg_replace('/\s+/', ' ', (string)($_POST['payer_name'] ?? '')));
        $fail  = function (string $m) use ($sid) { $_SESSION['pay_err'] = [$sid, $m]; $_SESSION['open_pay'] = $sid; };
        $paid  = round((float)str_replace(',', '', (string)($_POST['paid_amount'] ?? '0')), 2);
        if (!$sub) flash('error', 'That request is no longer waiting for payment.');
        elseif (!preg_match('/^[A-Z0-9-]{6,30}$/', $ref)) $fail('Enter the reference number exactly as shown on your payment receipt (letters and numbers only, 6 to 30 characters).');
        elseif (mb_strlen($payer) < 2 || mb_strlen($payer) > 80) $fail('Enter the name or number you paid from.');
        elseif ($paid <= 0 || $paid > 100000) $fail('Enter the amount you paid.');
        else {
            [$proof, $uerr] = save_private_upload($_FILES['proof'] ?? [], ID_TYPES, 3 * 1048576, 'payments');
            if ($uerr) $fail($uerr);
            else {
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare('UPDATE premium_subscriptions SET payment_ref = ?, payer_name = ?, paid_amount = ?, paid_at = NOW(), proof_path = COALESCE(?, proof_path) WHERE id = ?')
                        ->execute([$ref, $payer, $paid, $proof, $sid]);
                    $pdo->prepare("INSERT INTO notifications (user_id, message, link) SELECT id, CAST(? AS varchar), 'admin/premium.php' FROM users WHERE role = 'admin' AND status = 'active'")
                        ->execute(['Payment sent by ' . mb_substr($p['employer_name'], 0, 80) . ': P' . number_format($paid) . ' via ' . (PAYMENT_METHODS[$sub['payment_method']] ?? 'unknown') . ', ref ' . $ref . '. Please check your app and activate.']);
                    $pdo->commit();
                    if ($proof && $sub['proof_path']) delete_private($sub['proof_path']);
                    flash('success', 'Thank you! We received your payment details. Our admin will check them in the app and activate Premium.');
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    if ($proof) delete_private($proof);
                    if ($e instanceof PDOException && in_array($e->getCode(), ['23000', '23505'], true)) $fail('That reference number was already used on another request. Please check it on your receipt.');
                    else { error_log('Premium payment failed: ' . $e->getMessage()); $fail('Something went wrong. Please try again.'); }
                }
            }
        }
    } elseif ($act === 'cancel_request') {
        $sid = (int)($_POST['sub_id'] ?? 0);
        $q = $pdo->prepare("SELECT proof_path FROM premium_subscriptions WHERE id = ? AND employer_id = ? AND status = 'requested'");
        $q->execute([$sid, $uid]);
        $old = $q->fetch();
        if ($old) {
            $pdo->prepare("UPDATE premium_subscriptions SET status = 'cancelled', payment_ref = NULL, proof_path = NULL WHERE id = ? AND employer_id = ? AND status = 'requested'")->execute([$sid, $uid]);
            if ($old['proof_path']) delete_private($old['proof_path']);
        }
        flash('success', 'Request cancelled.');
    }
    redirect('employer/premium.php');
}

// Pending and active Premium, with the posts in each
$s = $pdo->prepare("SELECT * FROM premium_subscriptions WHERE employer_id = ? AND status IN ('requested','active') ORDER BY id DESC");
$s->execute([$uid]);
$subs = $s->fetchAll();
$subJobs = [];
if ($subs) {
    $in = implode(',', array_map('intval', array_column($subs, 'id')));
    foreach ($pdo->query("SELECT x.subscription_id, j.title FROM premium_subscription_jobs x JOIN job_posts j ON j.id = x.job_id WHERE x.subscription_id IN ($in) ORDER BY j.title")->fetchAll() as $r)
        $subJobs[(int)$r['subscription_id']][] = $r['title'];
}
$eligible = premium_eligible_jobs($pdo, $uid);
$activeCount = 0; $pendingCount = 0;
foreach ($subs as $x) { if ($x['status'] === 'active') $activeCount += (int)$x['post_count']; else $pendingCount += (int)$x['post_count']; }

$openPay = (int)($_SESSION['open_pay'] ?? 0); unset($_SESSION['open_pay']);
$payErr = $_SESSION['pay_err'] ?? null; unset($_SESSION['pay_err']);

$pageTitle = 'Premium';
require __DIR__ . '/../includes/emp_top.php';
?>
<header class="ep-head">
  <div><h1 class="dash-title ep-title">Premium</h1><p class="ep-sub">Boost the job posts you choose and reach more local job seekers.</p></div>
  <span class="ep-count"><?= $activeCount ? '&#9733; ' . $activeCount . ' Premium post' . ($activeCount > 1 ? 's' : '') : ($pendingCount ? 'Request pending' : 'Free plan') ?></span>
</header>

<?php foreach ($subs as $x): $isActive = $x['status'] === 'active'; $titles = $subJobs[(int)$x['id']] ?? []; ?>
  <div class="pm-status <?= $isActive ? 'pm-ok' : 'pm-wait' ?>"><span class="pm-ico"><?= $isActive ? '&#10003;' : 'i' ?></span>
    <div>
      <b><?= $isActive ? 'Premium is active' : (!empty($x['payment_ref']) ? 'Payment sent, the admin is checking it' : 'Request sent, waiting for your payment') ?> &middot; <?= (int)$x['post_count'] ?> post<?= (int)$x['post_count'] === 1 ? '' : 's' ?> &middot; ₱<?= number_format((float)$x['amount']) ?></b>
      <p><?= $isActive ? 'Until ' . e(date('M j, Y', strtotime($x['ends_on']))) . '. ' : 'Sent on ' . e(date('M j, Y', strtotime($x['created_at']))) . '. ' ?>
        Payment: <?= e(PAYMENT_METHODS[$x['payment_method']] ?? 'not set') ?>.</p>
      <?php if ($titles): ?><p class="pm-titles"><?= e(implode(' · ', $titles)) ?></p><?php endif; ?>
    </div>
    <?php if (!$isActive): $sentRef = !empty($x['payment_ref']); ?>
    <div class="pay-btns">
      <button type="button" class="btn btn-gold btn-sm" data-pay-open="<?= (int)$x['id'] ?>"><?= $sentRef ? 'Payment details' : 'Pay now' ?></button>
      <form method="post" class="pm-cancel"><?= csrf_field() ?><input type="hidden" name="sub_id" value="<?= (int)$x['id'] ?>"><button class="btn btn-outline btn-sm" name="action" value="cancel_request" data-confirm="Your Premium request will be cancelled." data-confirm-title="Cancel this request?" data-confirm-ok="Cancel request" data-confirm-tone="info" data-confirm-cancel="Keep request">Cancel request</button></form>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!$isActive):
    $acc = pay_account((string)$x['payment_method']);
    $mLabel = PAYMENT_METHODS[$x['payment_method']] ?? 'your chosen method';
    $code = pay_code((int)$x['id']);
    $hasDetails = $acc['number'] !== '' || $acc['qr'] !== '';
    $sentRef = !empty($x['payment_ref']);
    $amt = (float)$x['amount'];
  ?>
  <dialog class="pm-modal pay-modal" id="pay-dlg-<?= (int)$x['id'] ?>" data-method="<?= e((string)$x['payment_method']) ?>" aria-labelledby="pay-t-<?= (int)$x['id'] ?>">
    <div class="pm-modal-head">
      <div class="pay-title"><span class="pay-badge" aria-hidden="true"><?= e(strtoupper(substr($mLabel, 0, 1))) ?></span>
        <div><h2 id="pay-t-<?= (int)$x['id'] ?>">Pay with <?= e($mLabel) ?></h2>
          <p>Scan the QR code <b>or</b> type the number in your app.</p></div></div>
      <button type="button" class="pm-x" data-pay-close aria-label="Close">&times;</button>
    </div>
    <div class="pay-amount"><span>Amount to send</span><b>&#8369;<?= number_format($amt, 2) ?></b></div>

    <?php if (!$hasDetails): ?>
      <p class="pm-note" style="margin:16px 0">The <?= e($mLabel) ?> payment details are not set up yet. Please contact the admin through the Contact page, or cancel and choose another method.</p>
      <div class="pm-btns"><button class="btn btn-outline" type="button" data-pay-close>Close</button></div>
    <?php else: ?>
      <h3 class="pm-sub">1. Send the payment</h3>
      <div class="pay-grid">
        <?php if ($acc['qr'] !== ''): $qrUrl = public_file_url($acc['qr']); ?>
        <div class="pay-qr">
          <div class="pay-qr-frame"><img src="<?= e($qrUrl) ?>" alt="<?= e($mLabel) ?> QR code" width="160" height="160"></div>
          <a class="pay-save" href="<?= e($qrUrl) ?>" download="<?= e(strtolower($mLabel)) ?>-qr.<?= e(pathinfo($acc['qr'], PATHINFO_EXTENSION)) ?>">Save QR image</a>
        </div>
        <?php endif; ?>
        <div class="pay-info">
          <?php if ($acc['number'] !== ''): ?>
          <div class="pay-row pay-row--key pay-row--wide"><div><small><?= e($mLabel) ?> number</small><b><?= e($acc['number']) ?></b></div>
            <button type="button" class="pay-copy" data-copy="<?= e(preg_replace('/[^0-9+]/', '', $acc['number'])) ?>">Copy</button></div>
          <?php endif; ?>
          <?php if ($acc['name'] !== ''): ?><div class="pay-row"><div><small>Account name</small><b><?= e($acc['name']) ?></b></div></div><?php endif; ?>
          <div class="pay-row pay-row--code<?= $acc['name'] !== '' ? '' : ' pay-row--wide' ?>"><div><small>Put in message / notes</small><b><?= e($code) ?></b></div>
            <button type="button" class="pay-copy" data-copy="<?= e($code) ?>">Copy</button></div>
          <p class="pay-tip">On one phone? Save the QR image and upload it in your app's QR scanner, or just type the number.</p>
        </div>
      </div>

      <h3 class="pm-sub">2. Enter your payment details</h3>
      <?php if ($payErr && (int)$payErr[0] === (int)$x['id']): ?><p class="pay-err" role="alert"><?= e($payErr[1]) ?></p><?php endif; ?>
      <?php if ($sentRef): ?>
        <div class="pay-sent"><span class="pay-sent-ico">&#10003;</span>
          <div><b>We received your payment details</b>
            <p>Reference <code><?= e($x['payment_ref']) ?></code> &middot; &#8369;<?= number_format((float)$x['paid_amount'], 2) ?>. The admin will check it in the app and activate Premium. Wrong details? Update them below.</p></div></div>
      <?php endif; ?>
      <form method="post" enctype="multipart/form-data" class="form pay-form"><?= csrf_field() ?><input type="hidden" name="sub_id" value="<?= (int)$x['id'] ?>">
        <div class="pay-fields">
          <label><span class="pay-lab">Reference no. <em>(on your receipt)</em></span>
            <input name="payment_ref" required maxlength="30" autocomplete="off" value="<?= e($x['payment_ref'] ?? '') ?>" placeholder="Example: 1234567890123"></label>
          <label><span class="pay-lab">Amount you sent (&#8369;)</span>
            <input name="paid_amount" required inputmode="decimal" value="<?= e(number_format((float)($x['paid_amount'] ?: $amt), 2, '.', '')) ?>"></label>
          <label><span class="pay-lab">Paid from <em>(name or number)</em></span>
            <input name="payer_name" required maxlength="80" value="<?= e($x['payer_name'] ?? '') ?>" placeholder="Name in your app"></label>
          <label><span class="pay-lab">Screenshot <em>(optional, JPG or PNG, up to 3 MB)</em></span>
            <input type="file" name="proof" accept="image/png,image/jpeg">
            <?php if (!empty($x['proof_path'])): ?><span class="pay-lab"><em>Saved. <a href="<?= e(url('employer/pay-proof.php?id=' . (int)$x['id'])) ?>" target="_blank" rel="noopener">View</a></em></span><?php endif; ?></label>
        </div>
        <p class="pay-hint">&#8369;<?= number_format(PREMIUM_PRICE) ?> per post is one month. Paying for more months? Enter the full amount you sent and we will set the duration to match.</p>
        <div class="pm-btns">
          <button class="btn btn-gold pm-cta" name="action" value="submit_payment" type="submit"><?= $sentRef ? 'Update details' : 'I have paid, send for checking' ?></button>
          <button class="btn btn-outline" type="submit" name="action" value="cancel_request" formnovalidate>Cancel</button>
        </div>
      </form>
    <?php endif; ?>
  </dialog>
  <?php endif; ?>
<?php endforeach; ?>

<div class="pm-plans">
  <section class="card pm-plan">
    <div class="pm-top"><h2>Free</h2><span class="st st-off">Every post</span></div>
    <p class="pm-desc">Everything you need to start hiring locally.</p>
    <div class="pm-price"><b>&#8369;0</b><span>forever</span></div>
    <ul class="plan-list">
      <li>Normal ranking in search</li>
      <li>Up to <?= (int)FREE_ACTIVE_LIMIT ?> active job posts</li>
      <li>Verification badge</li>
    </ul>
  </section>

  <section class="card pm-plan pm-pro">
    <span class="pm-ribbon">Per post</span>
    <div class="pm-top"><h2>Premium <span class="pm-star">&#9733;</span></h2></div>
    <p class="pm-desc">Pick the posts you want to stand out.</p>
    <div class="pm-price"><b>&#8369;<?= (int)PREMIUM_PRICE ?></b><span>per month, for each post</span></div>
    <ul class="plan-list">
      <li>The post is shown in the Featured section on the home page</li>
      <li>Gold Featured badge on that post</li>
      <li>Higher ranking in search</li>
    </ul>
    <div class="pm-foot">
    <?php if ($canPost): ?>
      <button type="button" class="btn btn-gold pm-cta" id="open-boost">Subscribe</button>
    <?php else: ?>
      <p class="pm-note">Available once your account is verified. <a href="<?= url('employer/verification.php') ?>">Check verification</a></p>
    <?php endif; ?>
    </div>
  </section>
</div>

<?php if ($canPost): ?>
<dialog class="pm-modal" id="boost" aria-labelledby="boost-title">
  <div class="pm-modal-head">
    <h2 id="boost-title">Subscribe to Premium</h2>
    <button type="button" class="pm-x" id="close-boost" aria-label="Close">&times;</button>
  </div>
  <?php if (!$eligible): ?>
    <p class="small" style="margin:8px 0 16px">You have no job posts that can be boosted right now. Posts must be live or waiting for review, and not already in a Premium request.</p>
    <div class="pm-btns"><a class="btn btn-primary" href="<?= url('employer/post-job.php') ?>">Post a job</a><button class="btn btn-outline" type="button" id="close-boost-2">Close</button></div>
  <?php else: ?>
  <form method="post" class="form" id="boost-form" data-price="<?= (int)PREMIUM_PRICE ?>">
    <?= csrf_field() ?>
    <h3 class="pm-sub" style="margin-top:6px">1. Choose the posts to boost</h3>
    <p class="small" style="margin:0 0 10px">Each post costs ₱<?= (int)PREMIUM_PRICE ?> per month.</p>
    <div class="pm-jobs">
      <?php foreach ($eligible as $j): ?>
        <label class="check-row"><input type="checkbox" name="job_ids[]" value="<?= (int)$j['id'] ?>">
          <span><b><?= e($j['title']) ?></b> <small class="small">&middot; <?= $j['status'] === 'pending' ? 'waiting for review' : 'live' ?>, apply by <?= e(date('M j, Y', strtotime($j['deadline']))) ?></small></span></label>
      <?php endforeach; ?>
    </div>

    <h3 class="pm-sub">2. Payment method</h3>
    <p class="small" style="margin:0 0 8px">Pay by GCash or MariBank. After you confirm, our number and QR code will show on this page. Send the payment, enter the reference number from your receipt, and our admin will activate Premium once the payment is received.</p>
    <div class="pm-pay">
      <?php foreach (PAYMENT_METHODS as $k => $label): ?>
        <label class="check-row"><input type="radio" name="payment_method" value="<?= e($k) ?>" required> <span><?= e($label) ?></span></label>
      <?php endforeach; ?>
    </div>

    <div class="pm-total"><span><b id="boost-count">0</b> post<span id="boost-s">s</span> selected</span><strong>Total: ₱<span id="boost-total">0</span> <small>per month</small></strong></div>
    <div class="pm-btns">
      <button class="btn btn-gold pm-cta" name="action" value="request" type="submit">Confirm subscription</button>
      <button class="btn btn-outline" type="button" id="close-boost-2">Cancel</button>
    </div>
  </form>
  <script>
  (function () {
    var f = document.getElementById('boost-form'); if (!f) return;
    var price = parseInt(f.dataset.price, 10) || 0;
    function update() {
      var c = f.querySelectorAll('input[name="job_ids[]"]:checked').length;
      document.getElementById('boost-count').textContent = c;
      document.getElementById('boost-s').style.display = c === 1 ? 'none' : '';
      document.getElementById('boost-total').textContent = (c * price).toLocaleString('en-US');
    }
    f.addEventListener('change', update); update();
  })();
  </script>
  <?php endif; ?>
</dialog>
<script>
(function () {
  var dlg = document.getElementById('boost'), open = document.getElementById('open-boost');
  if (!dlg || !open) return;
  open.addEventListener('click', function () { if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', ''); });
  ['close-boost', 'close-boost-2'].forEach(function (id) {
    var b = document.getElementById(id); if (b) b.addEventListener('click', function () { dlg.close(); });
  });
  dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });   // click outside the box closes it
})();
</script>
<?php endif; ?>

<script>
(function () {
  function openPay(id) { var d = document.getElementById('pay-dlg-' + id); if (!d) return; if (d.showModal) d.showModal(); else d.setAttribute('open', ''); }
  document.querySelectorAll('[data-pay-open]').forEach(function (b) { b.addEventListener('click', function () { openPay(b.getAttribute('data-pay-open')); }); });
  document.querySelectorAll('.pay-modal').forEach(function (d) {
    d.addEventListener('click', function (e) { if (e.target === d) d.close(); });   // click outside closes it
    d.querySelectorAll('[data-pay-close]').forEach(function (b) { b.addEventListener('click', function () { d.close(); }); });
  });
  var auto = <?= (int)$openPay ?>; if (auto) openPay(auto);
})();
document.querySelectorAll('[data-copy]').forEach(function (b) {
  b.addEventListener('click', function () {
    var t = b.getAttribute('data-copy'), done = function () { var o = b.textContent; b.textContent = 'Copied'; setTimeout(function () { b.textContent = o; }, 1500); };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(t).then(done);
    else { var i = document.createElement('input'); i.value = t; document.body.appendChild(i); i.select(); try { document.execCommand('copy'); done(); } catch (e) {} document.body.removeChild(i); }
  });
});
</script>
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>
