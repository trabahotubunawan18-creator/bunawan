/* Trabaho Tu Bunawan - shared map helper (needs Leaflet loaded first)
 *
 *   TBBMap.viewer({map:'job-map', ll:[lat,lng], title:'Employer name'})
 *   TBBMap.picker({map:'pj-map', lat:'pj-lat', lng:'pj-lng', note:'pj-pin-note',
 *                  locate:'pj-locate', center:'pj-center'})
 */
(function () {
  'use strict';

  var CFG = {
    center: [8.15, 125.98],                     // Bunawan
    bounds: { latMin: 7.6, latMax: 8.7, lngMin: 125.4, lngMax: 126.5 }, // same limits as post-job.php
    nativeZoom: 18,   // highest zoom with real satellite detail. Zooming past it enlarges the
                      // last real tile instead of showing "map data not available".
                      // If your area is sharp at 19, raise this to 19.
    maxZoom: 20
  };
  var ESRI = 'https://server.arcgisonline.com/ArcGIS/rest/services/';

  function $(id) { return document.getElementById(id); }

  /* ---------- big, easy-to-see pin ---------- */
  function pinIcon() {
    return L.divIcon({
      className: 'tbb-pin',
      iconSize: [40, 52], iconAnchor: [20, 50], popupAnchor: [0, -46],
      html: '<span class="tbb-pin-pulse"></span>' +
            '<svg viewBox="0 0 40 52" width="40" height="52" aria-hidden="true">' +
            '<path d="M20 2C10.1 2 2 10 2 19.8 2 33 20 50 20 50s18-17 18-30.2C38 10 29.9 2 20 2z" fill="#E53935" stroke="#fff" stroke-width="3"/>' +
            '<circle cx="20" cy="20" r="7" fill="#fff"/></svg>'
    });
  }

  /* ---------- small button controls ---------- */
  function button(title, svg, onClick, position) {
    var C = L.Control.extend({
      options: { position: position || 'topleft' },
      onAdd: function (map) {
        var box = L.DomUtil.create('div', 'leaflet-bar tbb-ctl');
        var a = L.DomUtil.create('a', '', box);
        a.href = '#'; a.title = title; a.setAttribute('role', 'button'); a.setAttribute('aria-label', title);
        a.innerHTML = svg;
        L.DomEvent.disableClickPropagation(box);
        L.DomEvent.on(a, 'click', function (e) { L.DomEvent.preventDefault(e); onClick(map); });
        return box;
      }
    });
    return new C();
  }
  var SVG_FULL = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg>';
  var SVG_TARGET = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="6"/><path d="M12 2v4M12 18v4M2 12h4M18 12h4"/></svg>';

  function toggleFull(map) {
    var el = map.getContainer();
    var fsEl = document.fullscreenElement || document.webkitFullscreenElement;
    function cssOn() { el.classList.add('tbb-map-full'); document.documentElement.classList.add('tbb-lock'); refresh(); }
    function cssOff() { el.classList.remove('tbb-map-full'); document.documentElement.classList.remove('tbb-lock'); refresh(); }
    function refresh() { setTimeout(function () { map.invalidateSize(); }, 150); }
    if (fsEl === el) { (document.exitFullscreen || document.webkitExitFullscreen).call(document); return; }
    if (el.classList.contains('tbb-map-full')) { cssOff(); return; }
    var req = el.requestFullscreen || el.webkitRequestFullscreen;
    if (!req) { cssOn(); return; }
    try {
      var p = req.call(el);
      if (p && p.catch) p.catch(cssOn);
    } catch (e) { cssOn(); }
  }

  /* ---------- base map with satellite first ---------- */
  function create(id, o) {
    o = o || {};
    var map = L.map(id, {
      center: o.center || CFG.center, zoom: o.zoom || 13, maxZoom: CFG.maxZoom,
      scrollWheelZoom: o.wheel !== false, doubleClickZoom: o.dblzoom !== false
    });

    var sat = 'Imagery &copy; Esri, Maxar, Earthstar Geographics';
    var clarity = L.tileLayer('https://clarity.maptiles.arcgis.com/arcgis/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
      { maxNativeZoom: CFG.nativeZoom, maxZoom: CFG.maxZoom, attribution: sat });
    var classic = L.tileLayer(ESRI + 'World_Imagery/MapServer/tile/{z}/{y}/{x}',
      { maxNativeZoom: CFG.nativeZoom, maxZoom: CFG.maxZoom, attribution: sat });
    var street = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',
      { maxNativeZoom: 19, maxZoom: CFG.maxZoom, attribution: '&copy; OpenStreetMap contributors' });
    var places = L.tileLayer(ESRI + 'Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}',
      { maxNativeZoom: 18, maxZoom: CFG.maxZoom, zIndex: 5 });
    var roads = L.tileLayer(ESRI + 'Reference/World_Transportation/MapServer/tile/{z}/{y}/{x}',
      { maxNativeZoom: 18, maxZoom: CFG.maxZoom, zIndex: 6 });

    // If the sharper satellite layer cannot load at all, fall back to the classic one.
    var loaded = false, fails = 0;
    clarity.on('tileload', function () { loaded = true; });
    clarity.on('tileerror', function () {
      if (!loaded && ++fails >= 4 && map.hasLayer(clarity)) { map.removeLayer(clarity); classic.addTo(map); }
    });

    clarity.addTo(map);
    places.addTo(map);
    L.control.layers(
      { 'Satellite': clarity, 'Satellite (alternate)': classic, 'Street map': street },
      { 'Place names': places, 'Roads': roads },
      { collapsed: true }
    ).addTo(map);
    L.control.scale({ imperial: false, maxWidth: 110 }).addTo(map);
    button('Full screen', SVG_FULL, toggleFull).addTo(map);

    function resize() { setTimeout(function () { map.invalidateSize(); }, 200); }
    document.addEventListener('fullscreenchange', resize);
    document.addEventListener('webkitfullscreenchange', resize);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && map.getContainer().classList.contains('tbb-map-full')) toggleFull(map);
    });
    return { map: map };
  }

  /* ---------- read-only map on the job page ---------- */
  function viewer(o) {
    var m = create(o.map, { center: o.ll, zoom: o.zoom || 18, wheel: false });
    var map = m.map;
    var marker = L.marker(o.ll, { icon: pinIcon(), title: o.title || 'Work location', keyboard: false }).addTo(map);
    if (o.title) {
      var d = document.createElement('div');       // text only, so an employer name can never inject HTML
      d.className = 'tbb-pop'; d.textContent = o.title;
      marker.bindPopup(d);
    }
    button('Back to the pin', SVG_TARGET, function () { map.setView(o.ll, o.zoom || 18); }).addTo(map);
    map.once('click', function () { map.scrollWheelZoom.enable(); });   // page scroll is not hijacked until the map is clicked
    return m;
  }

  /* ---------- pin picker on the post-job form ---------- */
  function picker(o) {
    var latEl = $(o.lat), lngEl = $(o.lng), note = $(o.note);
    var has = latEl.value !== '' && lngEl.value !== '' && !isNaN(parseFloat(latEl.value));
    var start = has ? [parseFloat(latEl.value), parseFloat(lngEl.value)] : CFG.center;
    var m = create(o.map, { center: start, zoom: has ? 18 : 13, dblzoom: false });
    var map = m.map, marker = null, ring = null, icon = pinIcon();

    function say(msg, kind) { if (note) { note.textContent = msg; note.className = 'small tbb-note ' + (kind || ''); } }
    function inside(lat, lng) {
      var b = CFG.bounds;
      return lat >= b.latMin && lat <= b.latMax && lng >= b.lngMin && lng <= b.lngMax;
    }
    function put(lat, lng, msg) {
      if (!inside(lat, lng)) { say('That spot is outside the Bunawan area. Please move the map and try again.', 'warn'); return false; }
      if (!marker) {
        marker = L.marker([lat, lng], { icon: icon, draggable: true, title: 'Drag me to the exact place' }).addTo(map);
        marker.on('dragend', function () { var p = marker.getLatLng(); put(p.lat, p.lng); });
      } else marker.setLatLng([lat, lng]);
      latEl.value = lat.toFixed(7); lngEl.value = lng.toFixed(7);
      say(msg || ('Pin set (' + lat.toFixed(5) + ', ' + lng.toFixed(5) + '). Drag it to adjust.'), 'ok');
      return true;
    }
    if (has) put(start[0], start[1]); else say('No pin yet. Click the map where the work is.');

    map.on('click', function (e) { put(e.latlng.lat, e.latlng.lng); });

    // Pin at the middle of the screen: easy on phones, where a finger hides the spot
    if (o.center && $(o.center)) $(o.center).addEventListener('click', function () {
      var c = map.getCenter(); put(c.lat, c.lng);
    });

    // My current location, with the GPS accuracy circle
    if (o.locate && $(o.locate)) $(o.locate).addEventListener('click', function () {
      if (!navigator.geolocation) { say('Your browser cannot share your location.', 'warn'); return; }
      say('Finding you...');
      navigator.geolocation.getCurrentPosition(function (p) {
        var lat = p.coords.latitude, lng = p.coords.longitude, acc = Math.round(p.coords.accuracy || 0);
        if (ring) map.removeLayer(ring);
        if (acc) ring = L.circle([lat, lng], { radius: acc, color: '#1976d2', weight: 1, fillOpacity: .12 }).addTo(map);
        if (put(lat, lng, 'Pin set from your location' + (acc ? ' (accurate to about ' + acc + ' m)' : '') + '. Drag it onto the exact building.')) {
          map.setView([lat, lng], acc > 60 ? 18 : 19);
          if (acc > 100) say('Your location is only accurate to about ' + acc + ' m. Please drag the pin onto the exact building.', 'warn');
        }
      }, function () { say('Could not get your location. Click the map instead.', 'warn'); },
      { enableHighAccuracy: true, timeout: 12000 });
    });

    return m;
  }

  window.TBBMap = { viewer: viewer, picker: picker, config: CFG };
})();
