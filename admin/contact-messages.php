<?php
require __DIR__ . '/../includes/admin_auth.php';
require_admin();
$pdo = db();

// Messages sent through the public "Contact us" form (table: contact_messages)
$tabs = ['unread' => 'Unread', 'read' => 'Read', 'replied' => 'Replied', 'archived' => 'Archived', 'all' => 'All'];
$tab  = array_key_exists($_GET['tab'] ?? '', $tabs) ? $_GET['tab'] : 'unread';
$q    = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 10;

// Where to go back to after an action (keeps the tab, search and page)
$keep = array_filter(['tab' => $tab !== 'unread' ? $tab : '', 'q' => $q, 'page' => $page > 1 ? $page : ''], fn($v) => $v !== '');
$backUrl = 'admin/contact-messages.php' . ($keep ? '?' . http_build_query($keep) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { flash('error', 'Your session expired. Please try again.'); redirect($backUrl); }
    $act = $_POST['action'] ?? '';
    $id  = (int)($_POST['id'] ?? 0);
    $s = $pdo->prepare('SELECT id, status, email FROM contact_messages WHERE id = ?');
    $s->execute([$id]);
    $row = $s->fetch();
    if (!$row) { flash('error', 'That message no longer exists.'); redirect($backUrl); }

    $setStatus = ['read' => 'read', 'unread' => 'unread', 'replied' => 'replied', 'archive' => 'archived', 'restore' => 'read'];
    $done      = ['read' => 'Marked as read.', 'unread' => 'Marked as unread.', 'replied' => 'Marked as replied.', 'archive' => 'Message archived.', 'restore' => 'Message moved back to Read.'];
    if (isset($setStatus[$act])) {
        $pdo->prepare('UPDATE contact_messages SET status = ? WHERE id = ?')->execute([$setStatus[$act], $id]);
        log_admin('contact_message_' . $setStatus[$act], 'contact_message', $id);
        $msg = $done[$act];

        // When a message is marked as replied, tell the sender inside the system
        // (only works if a seeker/employer account uses the same email address).
        if ($act === 'replied' && $row['status'] !== 'replied') {
            $u = $pdo->prepare("SELECT id FROM users WHERE email = ? AND role IN ('seeker','employer') AND status = 'active' LIMIT 1");
            $u->execute([$row['email']]);
            if ($uid = $u->fetchColumn()) {
                $pdo->prepare('INSERT INTO notifications (user_id, message, link) VALUES (?,?,NULL)')
                    ->execute([(int)$uid, 'We replied to your message. Please check your email.']);
                $msg .= ' The user was also notified in the system.';
            } else {
                $msg .= ' No account uses this email, so only the email reply was sent.';
            }
        }
        flash('success', $msg);
    } elseif ($act === 'delete') {
        $pdo->prepare('DELETE FROM contact_messages WHERE id = ?')->execute([$id]);
        log_admin('contact_message_deleted', 'contact_message', $id);
        flash('success', 'Message deleted.');
    }
    redirect($backUrl);
}

// Count per status (for the tabs and the sidebar)
$tabCount = ['unread' => 0, 'read' => 0, 'replied' => 0, 'archived' => 0, 'all' => 0];
foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM contact_messages GROUP BY status') as $c) {
    if (isset($tabCount[$c['status']])) $tabCount[$c['status']] = (int)$c['n'];
    $tabCount['all'] += (int)$c['n'];
}

// List (filtered by tab and search)
$where = ' WHERE 1=1'; $args = [];
if ($tab !== 'all') { $where .= ' AND status = :st'; $args[':st'] = $tab; }
if ($q !== '') {
    $where .= ' AND (name ILIKE :q1 OR email ILIKE :q2 OR subject ILIKE :q3 OR message ILIKE :q4)';
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    foreach (['q1', 'q2', 'q3', 'q4'] as $k) $args[':' . $k] = $like;
}
$c = $pdo->prepare('SELECT COUNT(*) FROM contact_messages' . $where);
$c->execute($args);
$total = (int)$c->fetchColumn();
$page = min($page, max(1, (int)ceil($total / $per)));
$l = $pdo->prepare('SELECT id, name, email, subject, message, status, created_at FROM contact_messages' . $where
    . ' ORDER BY created_at DESC, id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
$l->execute($args);
$msgs = $l->fetchAll();

$stLabel = ['unread' => 'Unread', 'read' => 'Read', 'replied' => 'Replied', 'archived' => 'Archived'];
$tabDot  = ['unread' => '#F2B705', 'read' => '#9AA9A0', 'replied' => '#2FB463', 'archived' => '#6B7C72', 'all' => '#1B7F45'];
$emptyMsg = [
    'unread'   => 'You are all caught up. New messages from the Contact us page will show up here.',
    'read'     => 'No message is marked as read yet.',
    'replied'  => 'No message is marked as replied yet.',
    'archived' => 'No message has been archived.',
    'all'      => 'Nobody has used the Contact us form yet.',
];
$pagerBase = url('admin/contact-messages.php?' . http_build_query(array_filter(['tab' => $tab, 'q' => $q], fn($v) => $v !== '')));

$pageTitle = 'Contact Messages';
require __DIR__ . '/../includes/admin_top.php';
?>
<link rel="stylesheet" href="<?= e(url('assets/css/admin-contact-messages.css')) ?>?v=<?= filemtime(__DIR__ . '/../assets/css/admin-contact-messages.css') ?>">
<div class="cm-page">
  <div class="cm-hero">
    <div>
      <h1>Contact Messages</h1>
      <p>Messages sent through the Contact us page. Reply by email, then mark them as replied or archive them.</p>
    </div>
    <?php if ($tabCount['unread'] > 0): ?><span class="cm-chip cm-chip-gold"><?= icon('mail', 16) ?><?= number_format($tabCount['unread']) ?> unread</span>
    <?php else: ?><span class="cm-chip cm-chip-glass"><?= icon('check', 16) ?>All caught up</span><?php endif; ?>
  </div>

  <div class="cm-toolbar">
    <nav class="cm-tabs" aria-label="Filter messages">
      <?php foreach ($tabs as $k => $lbl): $tq = array_filter(['tab' => $k, 'q' => $q], fn($v) => $v !== ''); ?>
        <a class="<?= $tab === $k ? 'on' : '' ?>" href="<?= e(url('admin/contact-messages.php?' . http_build_query($tq))) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>>
          <i class="cm-dot" style="--c:<?= $tabDot[$k] ?>"></i><?= e($lbl) ?><span class="cm-badge"><?= $tabCount[$k] ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <form class="cm-search" method="get" action="<?= e(url('admin/contact-messages.php')) ?>" role="search">
      <input type="hidden" name="tab" value="<?= e($tab) ?>">
      <?= icon('search', 17) ?>
      <input name="q" type="search" maxlength="100" value="<?= e($q) ?>" placeholder="Search name, email, or message" aria-label="Search messages">
      <?php if ($q !== ''): ?><a class="cm-clear" href="<?= e(url('admin/contact-messages.php?tab=' . $tab)) ?>" aria-label="Clear search">&times;</a><?php endif; ?>
    </form>
  </div>

  <?php if (!$msgs): ?>
    <div class="cm-empty">
      <div class="cm-empty-ico"><?= icon($q !== '' ? 'search' : 'mail', 26) ?></div>
      <h3><?= $q !== '' ? 'No messages found' : 'No messages here' ?></h3>
      <p><?= $q !== '' ? 'Nothing matches &ldquo;' . e($q) . '&rdquo; in this tab. Try a different word or check the All tab.' : e($emptyMsg[$tab]) ?></p>
    </div>
  <?php endif; ?>

  <?php foreach ($msgs as $m):
      $st = $m['status'];
      $subj = trim((string)$m['subject']);
      $reSubj = 'Re: ' . ($subj !== '' ? $subj : 'Your message to Trabaho Tu Bunawan');
      $mailto = 'https://mail.google.com/mail/?view=cm&fs=1'
        . '&to=' . rawurlencode($m['email'])
        . '&su=' . rawurlencode($reSubj); // opens Gmail compose in a new tab
  ?>
  <article class="cm-card cm-<?= e($st) ?>" id="msg-<?= (int)$m['id'] ?>">
    <header class="cm-head">
      <span class="cm-av" aria-hidden="true"><?= e(initials($m['name'])) ?></span>
      <div class="cm-who">
        <h2><?= e($m['name']) ?></h2>
        <p class="cm-meta">
          <a href="<?= e($mailto) ?>" target="_blank" rel="noopener"><?= icon('mail', 14) ?><?= e($m['email']) ?></a>
          <span><?= icon('clock', 14) ?><?= e(date('M j, Y g:i A', strtotime($m['created_at']))) ?></span>
        </p>
      </div>
      <span class="cm-st cm-st-<?= e($st) ?>"><?= e($stLabel[$st] ?? ucfirst($st)) ?></span>
    </header>

    <div class="cm-body">
      <p class="cm-subject<?= $subj === '' ? ' none' : '' ?>"><?= $subj !== '' ? e($subj) : 'No subject' ?></p>
      <p class="cm-text"><?= e($m['message']) ?></p>
    </div>

    <form method="post" action="<?= e(url($backUrl)) ?>" class="cm-actions">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
      <a class="btn btn-primary btn-sm" href="<?= e($mailto) ?>" target="_blank" rel="noopener"><?= icon('send', 15) ?> Reply by email</a>
      <?php if ($st === 'unread'): ?>
        <button class="btn btn-outline btn-sm" name="action" value="read">Mark as read</button>
      <?php else: ?>
        <button class="btn btn-outline btn-sm" name="action" value="unread">Mark as unread</button>
      <?php endif; ?>
      <?php if ($st !== 'replied'): ?>
        <button class="btn btn-outline btn-sm" name="action" value="replied"><?= icon('check', 15) ?> Mark as replied</button>
      <?php endif; ?>
      <span class="cm-spacer"></span>
      <?php if ($st === 'archived'): ?>
        <button class="btn btn-outline btn-sm" name="action" value="restore">Restore</button>
      <?php else: ?>
        <button class="btn btn-outline btn-sm" name="action" value="archive">Archive</button>
      <?php endif; ?>
      <button class="btn btn-danger btn-sm" name="action" value="delete" data-confirm="This message will be permanently deleted." data-confirm-title="Delete message?" data-confirm-ok="Delete" data-confirm-tone="danger">Delete</button>
    </form>
  </article>
  <?php endforeach; ?>

  <?= pager($total, $page, $per, $pagerBase) ?>
</div>
<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>
