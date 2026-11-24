/* Trabaho Tu Bunawan - "Jobs near me"
 * Asks the browser for the visitor's location, puts it in the search form (lat / lng) and reloads the list.
 * The location is only used for this search. It is rounded to about 11 m and is not stored in the database.
 * Needs the markup printed by render_nearme() in includes/helpers.php. Geolocation only works on https (or localhost).
 */
(function () {
  'use strict';

  function init(box) {
    var form = box.closest('form');
    var lat = box.querySelector('[data-nm-lat]'), lng = box.querySelector('[data-nm-lng]');
    var btn = box.querySelector('[data-nm-btn]'), clear = box.querySelector('[data-nm-clear]');
    var status = box.querySelector('[data-nm-status]'), radius = box.querySelector('select[name="radius"]');
    var sort = form ? form.querySelector('select[name="sort"]') : null;
    if (!form || !lat || !lng || !btn || !status) return;
    var busy = false;

    function say(msg, kind) { status.textContent = msg || ''; status.className = 'nm-status' + (kind ? ' is-' + kind : ''); }
    function undoSort() { if (sort && sort.value === 'nearest' && !lat.value) sort.value = 'newest'; }
    function done() { busy = false; btn.disabled = false; }

    function locate() {
      if (busy) return;
      if (!navigator.geolocation) { say('Your browser cannot share your location.', 'warn'); undoSort(); return; }
      if (window.isSecureContext === false) { say('Location only works on a secure (https) page.', 'warn'); undoSort(); return; }
      busy = true; btn.disabled = true; say('Finding your location\u2026');
      navigator.geolocation.getCurrentPosition(function (p) {
        lat.value = p.coords.latitude.toFixed(4);
        lng.value = p.coords.longitude.toFixed(4);
        if (sort) sort.value = 'nearest';
        say('Got it. Loading jobs near you\u2026', 'ok');
        form.submit();
      }, function (err) {
        done(); undoSort();
        var m = 'We could not get your location. You can still pick a barangay instead.';
        if (err && err.code === 1) m = 'Location is blocked. Allow location for this site in your browser settings, then try again.';
        else if (err && err.code === 2) m = 'Your location is not available right now. Turn on GPS / Location and try again.';
        else if (err && err.code === 3) m = 'Finding your location took too long. Please try again.';
        say(m, 'warn');
      }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 60000 });
    }

    btn.addEventListener('click', locate);

    if (clear) clear.addEventListener('click', function () {
      lat.value = ''; lng.value = ''; if (radius) radius.value = '';
      if (sort && sort.value === 'nearest') sort.value = 'newest';
      form.submit();
    });

    // Choosing "Nearest to me" before sharing a location asks for it first
    if (sort) sort.addEventListener('change', function () { if (sort.value === 'nearest' && !lat.value) locate(); });

    // Searching with a distance (or "Nearest to me") but no location yet: get the location first
    form.addEventListener('submit', function (e) {
      var needs = !lat.value && ((radius && radius.value) || (sort && sort.value === 'nearest'));
      if (needs) { e.preventDefault(); locate(); }
    });
  }

  function start() { var l = document.querySelectorAll('[data-nearme]'); for (var i = 0; i < l.length; i++) init(l[i]); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
