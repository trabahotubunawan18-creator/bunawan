/* Trabaho Tu Bunawan - "Near me" chip
 * Tap once: asks the browser for the visitor's location, puts it in the search form (lat / lng) and reloads the list
 * with the closest jobs first. Tap again (the x): clears the location and goes back to the normal list.
 * The location is only used for this search. It is rounded to about 11 m and is not stored in the database.
 * Needs the markup printed by render_nearme() in includes/helpers.php. Geolocation only works on https (or localhost).
 */
(function () {
  'use strict';

  function init(box) {
    var form = box.closest('form');
    var lat = box.querySelector('[data-nm-lat]'), lng = box.querySelector('[data-nm-lng]');
    var btn = box.querySelector('[data-nm-btn]'), status = box.querySelector('[data-nm-status]');
    var sort = form ? form.querySelector('select[name="sort"]') : null;
    if (!form || !lat || !lng || !btn || !status) return;
    var busy = false, timer = null;

    function say(msg, kind, keep) {
      status.textContent = msg || ''; status.className = 'nm-status' + (kind ? ' is-' + kind : '');
      clearTimeout(timer); if (msg && !keep) timer = setTimeout(function () { status.textContent = ''; }, 6000);
    }
    function undoSort() { if (sort && sort.value === 'nearest' && !lat.value) sort.value = 'newest'; }
    function done() { busy = false; btn.disabled = false; }

    function locate() {
      if (busy) return;
      if (!navigator.geolocation) { say('Your browser cannot share your location.'); undoSort(); return; }
      if (window.isSecureContext === false) { say('Location only works on a secure (https) page.'); undoSort(); return; }
      busy = true; btn.disabled = true; say('Finding your location\u2026', 'ok', true);
      navigator.geolocation.getCurrentPosition(function (p) {
        lat.value = p.coords.latitude.toFixed(4);
        lng.value = p.coords.longitude.toFixed(4);
        if (sort) sort.value = 'nearest';
        form.submit();
      }, function (err) {
        done(); undoSort();
        var m = 'We could not get your location. You can still pick a barangay instead.';
        if (err && err.code === 1) m = 'Location is blocked. Allow location for this site in your browser settings, then try again.';
        else if (err && err.code === 2) m = 'Your location is not available. Turn on GPS / Location and try again.';
        else if (err && err.code === 3) m = 'Finding your location took too long. Please try again.';
        say(m);
      }, { enableHighAccuracy: false, timeout: 15000, maximumAge: 120000 });  // Wi-Fi / cell location is enough for barangay-level search and is faster on phones and laptops
    }

    function turnOff() {
      lat.value = ''; lng.value = '';
      if (sort && sort.value === 'nearest') sort.value = 'newest';
      form.submit();
    }

    // Coming back with the Back button can restore the page mid-search; reset it
    window.addEventListener('pageshow', function (ev) { if (ev.persisted) { done(); say(''); } });

    // One button: on = location already set, so a tap turns it off; off = a tap asks for the location
    btn.addEventListener('click', function () { if (lat.value) turnOff(); else locate(); });

    // Choosing "Nearest to me" in the Sort menu before sharing a location asks for it first
    if (sort) sort.addEventListener('change', function () { if (sort.value === 'nearest' && !lat.value) locate(); });
    form.addEventListener('submit', function (e) {
      if (sort && sort.value === 'nearest' && !lat.value) { e.preventDefault(); locate(); }
    });
  }

  function start() { var l = document.querySelectorAll('[data-nearme]'); for (var i = 0; i < l.length; i++) init(l[i]); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
