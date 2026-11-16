<?php
// Inbox tab bar shared by admin/complaints.php and admin/contact-messages.php.
// The styles are included right here, so nothing needs to be added to style.css.
// Uses $nComplaints and $nContact, which includes/admin_top.php already worked out.
$inboxCur = basename($_SERVER['SCRIPT_NAME']);
$inboxTabs = [
    ['admin/complaints.php',        'complaints.php',        'Complaints',       'flag', (int)$nComplaints],
    ['admin/contact-messages.php',  'contact-messages.php',  'Contact Messages', 'mail', (int)$nContact],
];
?>
<style>
/* ===== Admin Inbox tab bar (Complaints / Contact Messages) ===== */
.inbox-tabs{display:inline-flex;gap:4px;padding:4px;margin:0 0 var(--s4);background:var(--surface);border:1px solid var(--line);border-radius:999px;box-shadow:var(--shadow-xs);max-width:100%}
.inbox-tabs a{display:inline-flex;align-items:center;gap:8px;padding:9px 18px;border-radius:999px;font-size:.92rem;font-weight:600;line-height:1.2;color:var(--muted);text-decoration:none;white-space:nowrap;transition:background .15s,color .15s}
.inbox-tabs a svg{flex:none}
.inbox-tabs a:hover{background:var(--green-50);color:var(--green-700)}
.inbox-tabs a.on{background:var(--green);color:#fff;box-shadow:var(--shadow-sm)}
.inbox-tabs a:focus-visible{outline:none;box-shadow:var(--ring)}
.inbox-badge{min-width:22px;padding:1px 7px;border-radius:999px;background:var(--line);color:var(--ink-2);font-size:.75rem;font-style:normal;font-weight:700;line-height:1.5;text-align:center}
.inbox-badge.zero{opacity:.55}
.inbox-tabs a.on .inbox-badge{background:var(--gold);color:var(--gold-ink);opacity:1}
@media (max-width:560px){
  .inbox-tabs{display:flex;width:100%}
  .inbox-tabs a{flex:1;justify-content:center;padding:9px 10px;font-size:.85rem;white-space:normal;text-align:center}
}
</style>
<nav class="inbox-tabs" aria-label="Inbox">
  <?php foreach ($inboxTabs as [$href, $file, $label, $ic, $n]): ?>
    <a class="<?= $inboxCur === $file ? 'on' : '' ?>" href="<?= e(url($href)) ?>"<?= $inboxCur === $file ? ' aria-current="page"' : '' ?>>
      <?= icon($ic, 16) ?><span><?= e($label) ?></span><em class="inbox-badge<?= $n === 0 ? ' zero' : '' ?>"><?= $n ?></em>
    </a>
  <?php endforeach; ?>
</nav>
