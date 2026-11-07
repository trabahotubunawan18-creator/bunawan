// Skill picker: a dropdown multi-select with search, plus an "Other" box for typed skills.
// The server resolves typed skills again on save (synonyms, spelling), so this script only helps while typing.
(function () {
  function init() {
    document.querySelectorAll('.skp').forEach(function (box) {
      var maxCustom = parseInt(box.dataset.maxCustom, 10) || 3;
      var maxTotal = parseInt(box.dataset.maxTotal, 10) || 15;
      var catalog = [];
      try { catalog = JSON.parse(box.dataset.catalog || '[]'); } catch (e) {}
      var trig = box.querySelector('.skp-trigger'), trigText = box.querySelector('.skp-trig-text');
      var panel = box.querySelector('.skp-panel'), find = box.querySelector('.skp-find');
      var list = box.querySelector('.skp-list'), none = box.querySelector('.skp-none');
      var selFixed = box.querySelector('.skp-sel-fixed'), wrap = box.querySelector('.skp-custom');
      var input = box.querySelector('.skp-add input'), addBtn = box.querySelector('.skp-addbtn');
      var sug = box.querySelector('.skp-suggest'), msg = box.querySelector('.skp-msg'), closeBtn = box.querySelector('.skp-close');

      function say(t) { msg.textContent = t || ''; }
      function norm(s) { return s.toLowerCase().replace(/\s+/g, ' ').trim(); }
      function boxes() { return list.querySelectorAll('input[type=checkbox]'); }
      function customChips() { return wrap.querySelectorAll('.skp-cchip'); }
      function customs() { return Array.prototype.map.call(wrap.querySelectorAll('.skp-cchip input'), function (i) { return norm(i.value); }); }
      function total() { return list.querySelectorAll('input:checked').length + customChips().length; }

      // Rebuild the small chips under the button and the button text
      function sync() {
        selFixed.innerHTML = '';
        boxes().forEach(function (cb) {
          if (!cb.checked) return;
          var name = cb.parentNode.querySelector('span').textContent;
          var chip = document.createElement('span'); chip.className = 'skp-fchip';
          chip.appendChild(document.createTextNode(name));
          var x = document.createElement('button'); x.type = 'button'; x.className = 'skp-x';
          x.setAttribute('aria-label', 'Remove ' + name); x.innerHTML = '&times;';
          x.addEventListener('click', function () { cb.checked = false; sync(); });
          chip.appendChild(x); selFixed.appendChild(chip);
        });
        var n = total();
        trigText.textContent = n ? n + ' selected' : 'Choose skills';
        box.classList.toggle('has-sel', n > 0);
      }

      function setOpen(open) {
        panel.hidden = !open;
        trig.setAttribute('aria-expanded', open ? 'true' : 'false');
        box.classList.toggle('is-open', open);
        if (open) { find.focus(); }
      }
      trig.addEventListener('click', function () { setOpen(panel.hidden); });
      closeBtn.addEventListener('click', function () { setOpen(false); trig.focus(); });
      document.addEventListener('click', function (e) { if (!panel.hidden && !box.contains(e.target)) setOpen(false); });
      box.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) { setOpen(false); trig.focus(); } });

      // Search inside the list
      find.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
      find.addEventListener('input', function () {
        var q = norm(find.value), shown = 0;
        list.querySelectorAll('.skp-item').forEach(function (it) {
          var ok = q === '' || norm(it.textContent).indexOf(q) !== -1;
          it.style.display = ok ? '' : 'none';
          if (ok) shown++;
        });
        none.hidden = shown > 0;
      });

      list.addEventListener('change', function (e) {
        if (e.target.checked && total() > maxTotal) { e.target.checked = false; say('You reached the limit of ' + maxTotal + ' skills.'); }
        sync();
      });

      // "Did you mean ...?" while typing an own skill
      function suggestions(text) {
        var words = norm(text).split(' ').filter(function (w) { return w.length >= 3; });
        if (!words.length) return [];
        return catalog.filter(function (c) {
          var cw = norm(c.name).split(/[^a-z0-9]+/);
          return words.some(function (w) { return cw.some(function (x) { return x.indexOf(w) === 0 || (w.indexOf(x) === 0 && x.length >= 4); }); });
        }).slice(0, 4);
      }
      function tick(key) {
        var cbs = boxes();
        for (var i = 0; i < cbs.length; i++) if (cbs[i].value === key) { cbs[i].checked = true; return true; }
        return false;
      }
      function showSuggestions() {
        var found = suggestions(input.value);
        sug.innerHTML = '';
        if (!found.length) { sug.hidden = true; return; }
        var lead = document.createElement('span'); lead.textContent = 'Did you mean: '; sug.appendChild(lead);
        found.forEach(function (c) {
          var b = document.createElement('button'); b.type = 'button'; b.className = 'skp-sug'; b.textContent = c.name;
          b.addEventListener('click', function () {
            tick(c.key); sync();
            input.value = ''; sug.hidden = true; say(c.name + ' selected.'); input.focus();
          });
          sug.appendChild(b);
        });
        sug.hidden = false;
      }

      function addCustom() {
        var t = input.value.replace(/\s+/g, ' ').trim();
        if (t.length < 2) { say('Type at least 2 letters.'); return; }
        if (t.length > 60) { say('Keep it under 60 characters.'); return; }
        if (/https?:|www\.|@|\d{6,}/i.test(t)) { say('Please do not include links or phone numbers.'); return; }
        var exact = catalog.filter(function (c) { return norm(c.name) === norm(t); })[0];
        if (exact) { tick(exact.key); sync(); input.value = ''; sug.hidden = true; say(exact.name + ' selected.'); return; }
        if (customs().indexOf(norm(t)) !== -1) { say('You already added that.'); return; }
        if (customChips().length >= maxCustom) { say('You can add up to ' + maxCustom + ' of your own. Pick from the list when you can.'); return; }
        if (total() >= maxTotal) { say('You reached the limit of ' + maxTotal + ' skills.'); return; }
        var chip = document.createElement('span'); chip.className = 'skp-cchip';
        chip.appendChild(document.createTextNode(t));
        var hid = document.createElement('input'); hid.type = 'hidden'; hid.name = 'custom_skills[]'; hid.value = t; chip.appendChild(hid);
        var x = document.createElement('button'); x.type = 'button'; x.className = 'skp-x'; x.setAttribute('aria-label', 'Remove ' + t); x.innerHTML = '&times;'; chip.appendChild(x);
        wrap.appendChild(chip);
        input.value = ''; sug.hidden = true; say('Added. We will match it to our list when you save.');
        sync();
      }
      addBtn.addEventListener('click', addCustom);
      input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addCustom(); } });
      input.addEventListener('input', function () { say(''); showSuggestions(); });
      wrap.addEventListener('click', function (e) {
        var b = e.target.closest('.skp-x');
        if (b) { b.closest('.skp-cchip').remove(); say(''); sync(); }
      });

      sync();
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();