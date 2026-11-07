<?php
// Set $pageTitle (and optionally $bodyClass) before including this file.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
$pageTitle = $pageTitle ?? 'Trabaho Tu Bunawan';
$bodyClass = $bodyClass ?? '';
$flash = get_flash();
$announcement = setting('announcement');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle) ?> | Trabaho Tu Bunawan</title>
  <meta name="description" content="Local job opportunities in Bunawan, Agusan del Sur. Where Local Talent Meets Local Opportunity.">
  <meta property="og:image" content="<?= url('assets/img/og-share.jpg') ?>">
  <link rel="icon" href="<?= url('assets/img/favicon.ico') ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;family=Poppins:wght@600;700&amp;display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
</head>
<body class="<?= e($bodyClass) ?>">
<a class="skip" href="#main">Skip to content</a>
<?php if ($announcement !== ''): ?><div class="announce"><?= e($announcement) ?></div><?php endif; ?>
<header class="site-header">
  <div class="wrap nav">
    <a class="logo" href="<?= url('index.php') ?>" aria-label="Trabaho Tu Bunawan home">
      <img src="<?= url('assets/img/logo-icon.jpg') ?>" alt="" onerror="this.style.display='none'">
      <span>Trabaho Tu <b>Bunawan</b></span>
    </a>
    <button class="nav-toggle" aria-label="Open menu" aria-expanded="false" aria-controls="menu">
      <span></span><span></span><span></span>
    </button>
    <nav id="menu" class="menu">
      <?= nav_link('index.php', 'Home') ?>
      <?= nav_link('jobs.php', 'Find Jobs') ?>
      <?= nav_link('employers.php', 'For Employers') ?>
      <?= nav_link('about.php', 'About') ?>
      <?= nav_link('contact.php', 'Contact') ?>
      <?php if (is_logged_in()): ?>
        <a href="<?= url(dashboard_url($_SESSION['role'])) ?>">My Dashboard</a>
        <form method="post" action="<?= url('logout.php') ?>" class="inline"><?= csrf_field() ?><button class="linkbtn" type="submit">Log out</button></form>
      <?php else: ?>
        <a href="<?= url('login.php') ?>">Login</a>
        <a class="btn btn-gold btn-sm" href="<?= url(post_job_url()) ?>">Post a Job</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<?php if ($flash): ?>
  <div class="wrap"><div class="alert alert-<?= e($flash[0]) ?>" role="alert"><?= e($flash[1]) ?></div></div>
<?php endif; ?>
<main id="main">