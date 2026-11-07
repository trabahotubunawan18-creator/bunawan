</main>
    <footer class="app-foot">&copy; <?= date('Y') ?> Trabaho Tu Bunawan &middot; Local Skills. Local Jobs. Better Futures.</footer>
  </div>
</div>
<script src="<?= url('assets/js/confirm.js') ?>?v=<?= filemtime(__DIR__ . '/../assets/js/confirm.js') ?>"></script>
<script>
(function () {
  var app = document.getElementById('app');
  var t = document.getElementById('sideToggle'), s = document.getElementById('shade');
  function sync() { if (t) t.setAttribute('aria-expanded', app.classList.contains('open') ? 'true' : 'false'); }
  if (t) { t.setAttribute('aria-controls', 'side'); t.setAttribute('aria-expanded', 'false'); }
  if (t) t.addEventListener('click', function () { app.classList.toggle('open'); sync(); });
  if (s) s.addEventListener('click', function () { app.classList.remove('open'); sync(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && app.classList.contains('open')) { app.classList.remove('open'); sync(); if (t) t.focus(); }
  });
})();
</script>
</body>
</html>