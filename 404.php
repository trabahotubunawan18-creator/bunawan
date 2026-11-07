<?php
// Shown for missing pages. To use it for every missing URL on Apache, add this line to .htaccess:
//   ErrorDocument 404 /bunawan/404.php
http_response_code(404);
$pageTitle = 'Page not found';
$bodyClass = 'notfound-page';
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/back_button.php';
?>
<div class="wrap back-row"><?= back_button('index.php', 'Back', false) ?></div>
<section class="nf">
  <div class="wrap nf-inner">
    <p class="nf-code" aria-hidden="true">4<span>0</span>4</p>
    <h1>We could not find that page</h1>
    <p class="nf-lead">The link may be old or mistyped, or the job may have been closed. Try searching, or head back to a page below.</p>
    <form class="nf-search" action="<?= url('jobs.php') ?>" method="get" role="search">
      <label class="sr-only" for="nf-q">Search jobs</label>
      <input id="nf-q" name="q" type="search" maxlength="100" placeholder="Search jobs in Bunawan, like cashier or driver">
      <button class="btn btn-gold" type="submit"><?= icon('search', 16) ?> Search</button>
    </form>
    <div class="nf-links">
      <a class="btn btn-primary" href="<?= url('index.php') ?>">Go to home</a>
      <a class="btn btn-outline" href="<?= url('jobs.php') ?>">Browse all jobs</a>
      <a class="btn btn-outline" href="<?= url('contact.php') ?>">Contact us</a>
    </div>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>