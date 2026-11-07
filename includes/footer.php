</main>
<footer class="site-footer">
  <div class="wrap foot-grid">
    <div>
      <div class="logo logo-foot">Trabaho Tu <b>Bunawan</b></div>
      <p>Local Skills. Local Jobs. Better Futures.</p>
    </div>
    <div>
      <h4>Explore</h4>
      <a href="<?= url('jobs.php') ?>">Find Jobs</a>
      <a href="<?= url('register.php?role=employer') ?>">For Employers</a>
      <a href="<?= url('about.php') ?>">About</a>
      <a href="<?= url('contact.php') ?>">Contact</a>
    </div>
    <div>
      <h4>Information</h4>
      <a href="<?= url('privacy.php') ?>">Privacy Policy</a>
      <a href="<?= url('terms.php') ?>">Terms of Use</a>
      <a href="<?= url('safety.php') ?>">Safety Tips</a>
      <a href="<?= url('faq.php') ?>">FAQ</a>
    </div>
    <div>
      <h4>Location</h4>
      <p>Bunawan, Agusan del Sur, Philippines</p>
    </div>
  </div>
  <div class="foot-bottom">
    &copy; <?= date('Y') ?> Trabaho Tu Bunawan.
    <?php if (setting('sample_notice', '1') === '1'): ?> Demo mode: some listings may be sample data.<?php endif; ?>
  </div>
</footer>
<script src="<?= url('assets/js/confirm.js') ?>?v=<?= filemtime(__DIR__ . '/../assets/js/confirm.js') ?>"></script>
<script src="<?= url('assets/js/main.js') ?>"></script>
</body>
</html>