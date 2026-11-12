/*
 * Trabaho Tu Bunawan: in-page confirmation dialog.
 * Replaces the browser's "localhost says..." box with a centered dialog.
 *
 * Usage in markup (no JavaScript needed on the page):
 *   <button data-confirm="This job will be removed."
 *           data-confirm-title="Remove job?"        (optional)
 *           data-confirm-ok="Remove"                (optional, defaults to the button text)
 *           data-confirm-cancel="Keep it"           (optional, defaults to "Cancel")
 *           data-confirm-tone="danger">Remove</button>   (danger | info)
 *   <form data-confirm="..."> works the same way for forms.
 *
 * From JavaScript:
 *   ttConfirm('Message', {title, ok, cancel, tone}).then(function (yes) { ... });
 *   ttAlert('Message', {title});
 */
(function () {
  'use strict';

  var ICON_DANGER = '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg>';
  var ICON_INFO   = '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 2.5-3 4.5M12 17.5h.01"/></svg>';

  var root, card, icoEl, titleEl, msgEl, okBtn, cancelBtn, lastFocus, done;

  function build() {
    if (root) return;
    root = document.createElement('div');
    root.className = 'tt-modal';
    root.hidden = true;
    root.innerHTML =
      '<div class="tt-modal-backdrop"></div>' +
      '<div class="tt-modal-card" role="alertdialog" aria-modal="true" aria-labelledby="tt-modal-title" aria-describedby="tt-modal-msg">' +
        '<span class="tt-modal-ico" aria-hidden="true"></span>' +
        '<h2 class="tt-modal-title" id="tt-modal-title"></h2>' +
        '<p class="tt-modal-msg" id="tt-modal-msg"></p>' +
        '<div class="tt-modal-actions">' +
          '<button type="button" class="btn btn-outline tt-modal-cancel"></button>' +
          '<button type="button" class="btn tt-modal-ok"></button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(root);
    card = root.querySelector('.tt-modal-card');
    icoEl = root.querySelector('.tt-modal-ico');
    titleEl = root.querySelector('.tt-modal-title');
    msgEl = root.querySelector('.tt-modal-msg');
    okBtn = root.querySelector('.tt-modal-ok');
    cancelBtn = root.querySelector('.tt-modal-cancel');

    okBtn.addEventListener('click', function () { close(true); });
    cancelBtn.addEventListener('click', function () { close(false); });
    root.querySelector('.tt-modal-backdrop').addEventListener('click', function () { close(false); });
    root.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(false); return; }
      if (e.key === 'Tab') {                       // keep keyboard focus inside the dialog
        var items = [cancelBtn, okBtn].filter(function (b) { return !b.hidden; });
        var i = items.indexOf(document.activeElement);
        if (e.shiftKey && i <= 0) { e.preventDefault(); items[items.length - 1].focus(); }
        else if (!e.shiftKey && i === items.length - 1) { e.preventDefault(); items[0].focus(); }
      }
    }, true);
  }

  function close(result) {
    if (!root || root.hidden) return;
    root.classList.remove('is-open');
    document.documentElement.classList.remove('tt-modal-lock');
    setTimeout(function () { root.hidden = true; }, 140);
    if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
    var cb = done; done = null;
    if (cb) cb(result);
  }

  function show(message, o, alertOnly) {
    build();
    o = o || {};
    return new Promise(function (resolve) {
      if (done) done(false);                       // a previous dialog is still open: dismiss it
      done = resolve;
      lastFocus = document.activeElement;
      var danger = o.tone === 'danger';
      card.className = 'tt-modal-card tt-' + (danger ? 'danger' : 'info');
      icoEl.innerHTML = danger ? ICON_DANGER : ICON_INFO;
      titleEl.textContent = o.title || (alertOnly ? 'Notice' : (danger ? 'Are you sure?' : 'Please confirm'));
      msgEl.textContent = message || '';
      msgEl.hidden = !message;
      okBtn.textContent = o.ok || (alertOnly ? 'OK' : 'Yes, continue');
      okBtn.className = 'btn tt-modal-ok ' + (danger ? 'btn-danger' : 'btn-primary');
      cancelBtn.textContent = o.cancel || 'Cancel';
      cancelBtn.hidden = !!alertOnly;
      root.hidden = false;
      document.documentElement.classList.add('tt-modal-lock');
      void root.offsetWidth;                       // let the open animation run
      root.classList.add('is-open');
      // Destructive actions start on "Cancel" so an accidental Enter does not confirm them.
      (danger && !alertOnly ? cancelBtn : okBtn).focus();
    });
  }

  window.ttConfirm = function (message, o) { return show(message, o, false); };
  window.ttAlert = function (message, o) { return show(message, o, true); };

  function optsFor(el) {
    var tone = el.getAttribute('data-confirm-tone') ||
      (el.matches('.btn-danger,.btn-del,.cp-btn-strong,.danger') ? 'danger' : 'info');
    var ok = el.getAttribute('data-confirm-ok');
    if (!ok && el.tagName !== 'FORM') ok = (el.textContent || '').replace(/\s+/g, ' ').trim();
    return {
      title: el.getAttribute('data-confirm-title') || '',
      ok: ok || '',
      cancel: el.getAttribute('data-confirm-cancel') || '',
      tone: tone
    };
  }

  // Buttons and links with data-confirm
  document.addEventListener('click', function (e) {
    var el = e.target.closest ? e.target.closest('[data-confirm]') : null;
    if (!el || el.tagName === 'FORM') return;
    var form = el.form;
    // If a required field is empty, let the browser show its own "please fill this in" hint first.
    if (form && !el.hasAttribute('formnovalidate') && !form.noValidate && !form.checkValidity()) return;
    e.preventDefault();
    ttConfirm(el.getAttribute('data-confirm'), optsFor(el)).then(function (yes) {
      if (!yes) return;
      if (el.tagName === 'A' && el.href) { window.location.href = el.href; return; }
      if (!form) return;
      if (form.requestSubmit) { form.requestSubmit(el); return; }
      if (el.name) {                               // very old browsers: keep the button's name/value
        var h = document.createElement('input');
        h.type = 'hidden'; h.name = el.name; h.value = el.value;
        form.appendChild(h);
      }
      form.submit();
    });
  });

  // Whole forms with data-confirm
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || !f.hasAttribute || !f.hasAttribute('data-confirm')) return;
    if (f.__ttOk) { f.__ttOk = false; return; }
    e.preventDefault();
    var sub = e.submitter || null;
    ttConfirm(f.getAttribute('data-confirm'), optsFor(f)).then(function (yes) {
      if (!yes) return;
      f.__ttOk = true;
      if (f.requestSubmit) { sub ? f.requestSubmit(sub) : f.requestSubmit(); } else { f.submit(); }
    });
  });
})();

/* Messages: top banners (flash) auto-dismiss; form error boxes clear when the person starts fixing the form.
   Both get a close (X) button. Handles seeker, employer, admin, login and public pages. */
(function () {
  function addClose(box, dismiss) {
    box.classList.add('alert-flash');
    var x = document.createElement('button');
    x.type = 'button';
    x.className = 'alert-close';
    x.setAttribute('aria-label', 'Dismiss message');
    x.innerHTML = '&times;';
    x.addEventListener('click', dismiss);
    box.appendChild(x);
  }
  function remover(box) {
    var gone = false;
    return function () {
      if (gone) return;
      gone = true;
      box.classList.add('alert-hide');
      setTimeout(function () { if (box.parentNode) box.parentNode.removeChild(box); }, 400);
      return true;
    };
  }

  // 1) Top banners (flash messages): fade out by themselves
  var banners = document.querySelectorAll('.app-main > .alert, body > .wrap > .alert, .al-sub + .alert:not([role])');
  banners.forEach(function (box) {
    var timer = null, out = remover(box);
    var delay = box.classList.contains('alert-error') ? 8000 : 5000;
    function dismiss() { clearTimeout(timer); out(); }
    function start() { clearTimeout(timer); timer = setTimeout(dismiss, delay); }
    addClose(box, dismiss);
    start();
    box.addEventListener('mouseenter', function () { clearTimeout(timer); });
    box.addEventListener('mouseleave', start);
    box.addEventListener('focusin', function () { clearTimeout(timer); });
    box.addEventListener('focusout', start);
    document.addEventListener('click', function (ev) { if (!box.contains(ev.target)) dismiss(); });
    document.addEventListener('input', dismiss);
  });

  // 2) Form error boxes (login, register, settings, post job, admin review...):
  //    stay until the person starts typing or changes a field, or presses X
  document.querySelectorAll('.alert-error:not(.alert-flash)').forEach(function (box) {
    var out = remover(box);
    function dismiss() { out(); }
    addClose(box, dismiss);
    function onEdit(ev) { if (!box.contains(ev.target)) dismiss(); }
    document.addEventListener('input', onEdit);
    document.addEventListener('change', onEdit);
  });
})();
