<?php
// A "Back" button for the top-left of a page (below the site header).
// It returns the visitor to the page they came from. If they opened this page directly
// (no previous page on this site), it goes to $fallback instead.
require_once __DIR__ . '/auth.php';

function back_button(string $fallback = 'index.php', string $label = 'Back', bool $onDark = true): string {
    static $scriptAdded = false;
    $html = '<a class="back-btn' . ($onDark ? ' back-btn-dark' : '') . '" href="' . e(url($fallback)) . '" data-back-btn>'
          . '<svg class="ico" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>'
          . '<span>' . e($label) . '</span></a>';
    if (!$scriptAdded) {
        $scriptAdded = true;
        $html .= <<<'JS'
<script>
document.addEventListener('click', function (ev) {
  var a = ev.target.closest && ev.target.closest('[data-back-btn]');
  if (!a || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.button) return;
  var ok = false;
  try {
    var r = new URL(document.referrer);
    ok = r.origin === location.origin && r.pathname !== location.pathname;
  } catch (e) {}
  if (ok && history.length > 1) { ev.preventDefault(); history.back(); }
});
</script>
JS;
    }
    return $html;
}