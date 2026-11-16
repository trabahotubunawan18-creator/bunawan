<?php
// Inbox tab bar shared by admin/complaints.php and admin/contact-messages.php.
// Uses $nComplaints and $nContact, which includes/admin_top.php already worked out.
$inboxCur = basename($_SERVER['SCRIPT_NAME']);
$inboxTabs = [
    ['admin/complaints.php',        'complaints.php',        'Complaints',       'flag', (int)$nComplaints],
    ['admin/contact-messages.php',  'contact-messages.php',  'Contact Messages', 'mail', (int)$nContact],
];
?>
<nav class="inbox-tabs" aria-label="Inbox">
  <?php foreach ($inboxTabs as [$href, $file, $label, $ic, $n]): ?>
    <a class="<?= $inboxCur === $file ? 'on' : '' ?>" href="<?= e(url($href)) ?>"<?= $inboxCur === $file ? ' aria-current="page"' : '' ?>>
      <?= icon($ic, 16) ?><span><?= e($label) ?></span><em class="inbox-badge<?= $n === 0 ? ' zero' : '' ?>"><?= $n ?></em>
    </a>
  <?php endforeach; ?>
</nav>
