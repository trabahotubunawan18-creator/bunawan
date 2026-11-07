<?php
// Employer dashboard layout: dark sidebar + top bar.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
$cur = basename($_SERVER['SCRIPT_NAME']);
$pe = db()->prepare('SELECT employer_name, verification_status, logo_path FROM employer_profiles WHERE user_id = ?');
$pe->execute([$_SESSION['uid']]);
$emp = $pe->fetch() ?: ['employer_name' => $_SESSION['name'], 'verification_status' => 'pending', 'logo_path' => null];
[$vLabel, $vClass, $canPost] = verif_info($emp['verification_status']);
$q = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
$q->execute([$_SESSION['uid']]);
$unread = (int)$q->fetchColumn();
$flash = get_flash();
$first = explode(' ', trim($_SESSION['name']))[0];
$navItems = [
    ['employer/index.php', 'index.php', 'Overview', 'home', false],
    ['employer/post-job.php', 'post-job.php', 'Post a job', 'plus', !$canPost],
    ['employer/my-jobs.php', 'my-jobs.php', 'My job posts', 'list', false],
    ['employer/applicants.php', 'applicants.php', 'Applicants', 'users', false],
    ['employer/company-profile.php', 'company-profile.php', 'Company profile', 'building', false],
    ['employer/verification.php', 'verification.php', 'Verification', 'shield', false],
    ['employer/premium.php', 'premium.php', 'Premium', 'star', false],
    ['employer/notifications.php', 'notifications.php', 'Notifications', 'bell', false],
    ['employer/settings.php', 'settings.php', 'Settings', 'cog', false],
];
$cssVer = filemtime(__DIR__ . '/../assets/css/style.css');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle ?? 'Employer') ?> | Trabaho Tu Bunawan</title>
  <link rel="icon" href="<?= url('assets/img/favicon.ico') ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;family=Poppins:wght@600;700&amp;display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>?v=<?= $cssVer ?>">
</head>
<body class="app-page emp-page">
<div class="app" id="app">
  <aside class="side" id="side">
    <a class="side-logo" href="<?= url('employer/index.php') ?>" aria-label="Dashboard home">
      <img src="<?= url('assets/img/logo-icon.jpg') ?>" alt="" onerror="this.style.display='none'">
      <span>Trabaho Tu <b>Bunawan</b></span>
    </a>
    <div class="side-emp">
      <div class="side-emp-top">
        <span class="av av-sq"><?php if ($emp['logo_path']): ?><img src="<?= url('uploads/' . $emp['logo_path']) ?>" alt=""><?php else: ?><?= e(initials($emp['employer_name'])) ?><?php endif; ?></span>
        <div class="side-emp-meta"><b title="<?= e($emp['employer_name']) ?>"><?= e($emp['employer_name']) ?></b><small>Employer account</small></div>
      </div>
      <span class="vs side-vs <?= e($vClass) ?>"><?php if ($canPost): ?><i class="side-tick"><?= icon('check', 11) ?></i><?php else: ?><i class="side-dot"></i><?php endif; ?><?= e($vLabel) ?></span>
    </div>
    <p class="side-label">Employer</p>
    <nav class="side-nav" aria-label="Dashboard menu">
      <?php foreach ($navItems as [$href, $file, $label, $ic, $locked]): ?>
        <a href="<?= url($href) ?>" <?= $cur === $file ? 'class="on" aria-current="page"' : '' ?>>
          <?= icon($ic, 19) ?><span><?= e($label) ?></span>
          <?php if ($file === 'notifications.php' && $unread > 0): ?><em class="count"><?= $unread ?></em><?php endif; ?>
          <?php if ($locked): ?><span class="lockico" title="Available after verification"><?= icon('lock', 14) ?></span><?php endif; ?>
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
      <div class="top-title"><span class="top-crumb">Employer</span><?= e($pageTitle ?? '') ?></div>
      <div class="top-right">
        <?php if ($canPost): ?>
          <a class="btn btn-gold btn-sm" href="<?= url('employer/post-job.php') ?>"><?= icon('plus', 16) ?> Post a Job</a>
        <?php else: ?>
          <a class="btn btn-outline btn-sm" href="<?= url('employer/verification.php') ?>" title="You can post jobs after verification"><?= icon('lock', 15) ?> Post a Job</a>
        <?php endif; ?>
        <a class="icon-btn" href="<?= url('employer/notifications.php') ?>" aria-label="Notifications"><?= icon('bell', 20) ?>
          <?php if ($unread > 0): ?><em class="badge-dot"><?= $unread > 9 ? '9+' : $unread ?></em><?php endif; ?></a>
        <a class="user-chip" href="<?= url('employer/company-profile.php') ?>"><span class="av"><?= e(initials($_SESSION['name'])) ?></span><span class="uname"><?= e($first) ?></span></a>
      </div>
    </header>
    <main class="app-main" id="main">
      <?php if ($flash): ?><div class="alert alert-<?= e($flash[0]) ?>" role="alert"><?= e($flash[1]) ?></div><?php endif; ?>