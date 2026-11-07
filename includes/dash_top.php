<?php
// Job seeker dashboard layout: dark sidebar + top bar (no public website menu).
// Pages set $pageTitle, then require this file, and finish with dash_bottom.php.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
$cur = basename($_SERVER['SCRIPT_NAME']);
$q = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
$q->execute([$_SESSION['uid']]);
$unread = (int)$q->fetchColumn();
$flash = get_flash();
$first = explode(' ', trim($_SESSION['name']))[0];
$navItems = [
    ['seeker/index.php', 'index.php', 'Overview', 'home'],
    ['seeker/browse.php', 'browse.php', 'Browse jobs', 'search'],
    ['seeker/saved.php', 'saved.php', 'Saved jobs', 'bookmark'],
    ['seeker/applications.php', 'applications.php', 'My applications', 'send'],
    ['seeker/profile.php', 'profile.php', 'My profile', 'user'],
    ['seeker/resume.php', 'resume.php', 'Resume', 'file'],
    ['seeker/notifications.php', 'notifications.php', 'Notifications', 'bell'],
    ['seeker/settings.php', 'settings.php', 'Settings', 'cog'],
];
$cssVer = filemtime(__DIR__ . '/../assets/css/style.css');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle ?? 'Dashboard') ?> | Trabaho Tu Bunawan</title>
  <link rel="icon" href="<?= url('assets/img/favicon.ico') ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;family=Poppins:wght@600;700&amp;display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>?v=<?= $cssVer ?>">
</head>
<body class="app-page">
<div class="app" id="app">
  <aside class="side" id="side">
    <a class="side-logo" href="<?= url('seeker/index.php') ?>" aria-label="Dashboard home">
      <img src="<?= url('assets/img/logo-icon.jpg') ?>" alt="" onerror="this.style.display='none'">
      <span>Trabaho Tu <b>Bunawan</b></span>
    </a>
    <p class="side-label">Job seeker</p>
    <nav class="side-nav" aria-label="Dashboard menu">
      <?php foreach ($navItems as [$href, $file, $label, $ic]): ?>
        <a href="<?= url($href) ?>" <?= $file !== '' && ($cur === $file || ($file === 'browse.php' && $cur === 'job.php')) ? 'class="on" aria-current="page"' : '' ?>>
          <?= icon($ic, 19) ?><span><?= e($label) ?></span>
          <?php if ($file === 'notifications.php' && $unread > 0): ?><em class="count"><?= $unread ?></em><?php endif; ?>
        </a>
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
      <form class="top-search" action="<?= url('seeker/browse.php') ?>" method="get" role="search">
        <?= icon('search', 18) ?>
        <input name="q" type="search" maxlength="100" placeholder="Search jobs in Bunawan" aria-label="Search jobs">
      </form>
      <div class="top-right">
        <a class="icon-btn" href="<?= url('seeker/notifications.php') ?>" aria-label="Notifications"><?= icon('bell', 20) ?>
          <?php if ($unread > 0): ?><em class="badge-dot"><?= $unread > 9 ? '9+' : $unread ?></em><?php endif; ?></a>
        <a class="user-chip" href="<?= url('seeker/profile.php') ?>"><span class="av"><?= e(initials($_SESSION['name'])) ?></span><span class="uname"><?= e($first) ?></span></a>
      </div>
    </header>
    <main class="app-main" id="main">
      <?php if ($flash): ?><div class="alert alert-<?= e($flash[0]) ?>" role="alert"><?= e($flash[1]) ?></div><?php endif; ?>