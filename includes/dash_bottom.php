</main>
    <footer class="app-foot">&copy; <?= date('Y') ?> Trabaho Tu Bunawan &middot; Local Skills. Local Jobs. Better Futures.</footer>
  </div>
</div>
<script src="<?= url('assets/js/confirm.js') ?>?v=<?= filemtime(__DIR__ . '/../assets/js/confirm.js') ?>"></script>
<script>
(function () {
  var app = document.getElementById('app');
  var t = document.getElementById('sideToggle'), s = document.getElementById('shade');
  var root = document.documentElement;
  function setH() { root.style.setProperty('--app-h', ((window.visualViewport && window.visualViewport.height) || window.innerHeight) + 'px'); }
  function sync() {
    var open = app.classList.contains('open');
    if (t) t.setAttribute('aria-expanded', open ? 'true' : 'false');
    root.classList.toggle('nav-open', open);   // stop the page scrolling behind the menu
    setH();
  }
  setH();
  window.addEventListener('resize', setH);
  if (window.visualViewport) window.visualViewport.addEventListener('resize', setH);
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
