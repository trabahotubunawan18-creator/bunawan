<?php
// Admin dashboard layout. Pages call require_admin() first, set $pageTitle, then include this file.
require_once __DIR__ . '/admin_auth.php';
$cur = basename($_SERVER['SCRIPT_NAME']);
$cnt = function (string $sql) { return (int)db()->query($sql)->fetchColumn(); };
$nVerif  = $cnt("SELECT COUNT(*) FROM employer_profiles WHERE verification_status = 'pending'");
$nFlag   = $cnt("SELECT COUNT(*) FROM job_posts WHERE status = 'pending'");
$nComplaints = $cnt("SELECT COUNT(*) FROM job_reports WHERE status = 'open'")
    + (applicant_reports_ready(db()) ? $cnt("SELECT COUNT(*) FROM applicant_reports WHERE status = 'open'") : 0);
$nContact = $cnt("SELECT COUNT(*) FROM contact_messages WHERE status = 'unread'");
$nPremium = $cnt("SELECT COUNT(*) FROM premium_subscriptions WHERE status = 'requested'");
$nUnread = $cnt('SELECT COUNT(*) FROM notifications WHERE user_id = ' . (int)$_SESSION['uid'] . ' AND is_read = 0');
$flash = get_flash();
$cssVer = filemtime(__DIR__ . '/../assets/css/style.css');
// [link, file, label, icon, count, built?]
$navGroups = ['verification.php' => 'Moderation', 'reports.php' => 'Insights & content', 'activity-log.php' => 'System'];
$navItems = [
    ['admin/index.php', 'index.php', 'Dashboard', 'home', 0, true],
    ['admin/verification.php', 'verification.php', 'Employer verification', 'shield', $nVerif, true],
    ['admin/flagged.php', 'flagged.php', 'Flagged posts', 'list', $nFlag, true],
    ['admin/jobs.php', 'jobs.php', 'Jobs', 'briefcase', 0, true],
    ['admin/users.php', 'users.php', 'Users', 'users', 0, true],
    ['admin/complaints.php', 'complaints.php', 'Complaints', 'flag', $nComplaints, true],
    ['admin/contact-messages.php', 'contact-messages.php', 'Contact Messages', 'mail', $nContact, true],
    ['admin/reports.php', 'reports.php', 'Reports', 'chart', 0, true],
    ['admin/categories.php', 'categories.php', 'Categories', 'tag', 0, true],
    ['admin/skills.php', 'skills.php', 'Skills', 'bolt', 0, true],
    ['admin/premium.php', 'premium.php', 'Premium', 'star', $nPremium, true],
    ['admin/activity-log.php', 'activity-log.php', 'Activity log', 'clock', 0, true],
    ['admin/settings.php', 'settings.php', 'Settings', 'cog', 0, true],
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($pageTitle ?? 'Admin') ?> | Admin | Trabaho Tu Bunawan</title>
  <link rel="icon" href="<?= url('assets/img/favicon.ico') ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;family=Poppins:wght@600;700&amp;display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>?v=<?= $cssVer ?>">
</head>
<body class="app-page admin-page">
<div class="app" id="app">
  <aside class="side" id="side">
    <a class="side-logo" href="<?= url('admin/index.php') ?>">
      <img src="<?= url('assets/img/logo-icon.jpg') ?>" alt="" onerror="this.style.display='none'">
      <span>Trabaho Tu <b>Bunawan</b></span>
    </a>
    <p class="side-label">Administration</p>
    <nav class="side-nav" aria-label="Admin menu">
      <?php foreach ($navItems as [$href, $file, $label, $ic, $n, $built]): ?>
        <?php if (isset($navGroups[$file])): ?><p class="side-label"><?= e($navGroups[$file]) ?></p><?php endif; ?>
        <?php if ($built): ?>
          <a href="<?= url($href) ?>" <?= ($cur === $file || ($file === 'verification.php' && $cur === 'verify-review.php') || ($cur === 'job-review.php' && (($_GET['from'] ?? '') === 'flagged' ? $file === 'flagged.php' : $file === 'jobs.php'))) ? 'class="on" aria-current="page"' : '' ?>>
            <?= icon($ic, 19) ?><span><?= e($label) ?></span><?php if ($n > 0): ?><em class="count"><?= $n ?></em><?php endif; ?>
          </a>
        <?php else: ?>
          <span class="soon" title="Coming in the next phase"><?= icon($ic, 19) ?><span><?= e($label) ?></span><em>Soon</em></span>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <div class="side-foot">
      <form method="post" action="<?= url('logout.php') ?>"><?= csrf_field() ?>
        <button class="side-link" type="submit"><?= icon('logout', 17) ?> Log out</button></form>
    </div>
  </aside>
  <div class="side-shade" id="shade"></div>
  <div class="app-col">
    <header class="topbar">
      <button class="side-toggle" id="sideToggle" aria-label="Open menu" type="button"><?= icon('menu', 22) ?></button>
      <a class="top-brand" href="<?= url('admin/index.php') ?>">Trabaho Tu <b>Bunawan</b></a>
      <form class="top-search" action="<?= url('admin/users.php') ?>" method="get" role="search">
        <?= icon('search', 18) ?>
        <input name="q" type="search" maxlength="100" placeholder="Search users and employers" aria-label="Search users and employers">
      </form>
      <div class="top-right">
        <span class="admin-tag">Admin</span>
        <span class="user-chip"><span class="av"><?= e(initials($_SESSION['name'])) ?></span><span class="uname"><?= e($_SESSION['name']) ?></span></span>
      </div>
    </header>
    <main class="app-main" id="main">
      <?php if ($flash): ?><div class="alert alert-<?= e($flash[0]) ?>" role="alert"><?= e($flash[1]) ?></div><?php endif; ?>
