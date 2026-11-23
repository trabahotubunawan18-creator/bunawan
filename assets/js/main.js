// Mobile menu
const toggle = document.querySelector('.nav-toggle');
const menu = document.getElementById('menu');
if (toggle && menu) {
  toggle.addEventListener('click', () => {
    const open = menu.classList.toggle('open');
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
}

// Login / Register tabs
function showTab(name) {
  // Clear any login/register error message when switching tabs
  document.querySelectorAll('.auth-card .alert').forEach(a => a.remove());
  document.querySelectorAll('.tab').forEach(t => {
    const on = t.dataset.tab === name;
    t.classList.toggle('active', on);
    t.setAttribute('aria-selected', on ? 'true' : 'false');
  });
  document.querySelectorAll('.panel').forEach(p => p.classList.toggle('show', p.id === 'panel-' + name));
}
document.querySelectorAll('.tab').forEach(t => t.addEventListener('click', () => showTab(t.dataset.tab)));
document.querySelectorAll('[data-tab-link]').forEach(a => a.addEventListener('click', e => {
  e.preventDefault(); showTab(a.dataset.tabLink);
}));

// Show / hide password (eye button). Works for every <button data-toggle-pw="input-id">.
const EYE_ON  = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
const EYE_OFF = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
document.querySelectorAll('[data-toggle-pw]').forEach(btn => {
  const input = document.getElementById(btn.dataset.togglePw);
  if (!input) return;
  const paint = () => {
    const shown = input.type === 'text';
    btn.innerHTML = shown ? EYE_OFF : EYE_ON;            // eye = "show", crossed eye = "hide"
    btn.setAttribute('aria-label', shown ? 'Hide password' : 'Show password');
    btn.setAttribute('aria-pressed', shown ? 'true' : 'false');
    btn.title = shown ? 'Hide password' : 'Show password';
  };
  paint();
  btn.addEventListener('click', () => {
    input.type = input.type === 'password' ? 'text' : 'password';
    paint();
  });
});

// Password checklist + strength bar + "passwords match" message.
// Markup: <div class="meter pw-meter" data-pw-meter="PASS_ID"><span></span></div>
//         <ul class="pw-rules" data-pw-rules="PASS_ID"></ul>
//         <p class="pw-match" data-pw-match="CONFIRM_ID" data-pw-with="PASS_ID" hidden></p>
(function () {
  const RULES = [
    { t: 'At least 8 characters',        ok: v => v.length >= 8 },
    { t: 'One uppercase letter (A-Z)',   ok: v => /[A-Z]/.test(v) },
    { t: 'One lowercase letter (a-z)',   ok: v => /[a-z]/.test(v) },
    { t: 'One number (0-9)',             ok: v => /\d/.test(v) },
    { t: 'One symbol (! @ # $ % & *)',   ok: v => /[^A-Za-z0-9]/.test(v) }
  ];
  const svg = (inner) => '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + inner + '</svg>';
  const IC_OFF = svg('<circle cx="12" cy="12" r="9"/>');
  const IC_OK  = '<svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><circle cx="12" cy="12" r="10" fill="currentColor"/><path d="M7.5 12.5l3 3 6-6.5" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  const IC_BAD = svg('<circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/>');

  document.querySelectorAll('[data-pw-rules]').forEach(list => {
    const input = document.getElementById(list.dataset.pwRules);
    if (!input) return;
    const bar = document.querySelector('[data-pw-meter="' + input.id + '"] span');
    list.innerHTML = RULES.map(r => '<li><span class="pw-ic pw-ic-off">' + IC_OFF + '</span><span class="pw-ic pw-ic-ok">' + IC_OK + '</span><span>' + r.t + '</span></li>').join('');
    const items = list.querySelectorAll('li');
    const paint = () => {
      const v = input.value;
      let met = 0;
      RULES.forEach((r, i) => { const ok = r.ok(v); if (ok) met++; items[i].classList.toggle('ok', ok); });
      if (bar) {
        bar.style.width = (met / RULES.length * 100) + '%';
        bar.style.background = met <= 2 ? '#D64545' : (met === 3 ? '#E89B0C' : '#1B7F45');
      }
    };
    input.addEventListener('input', paint);
    paint();
  });

  document.querySelectorAll('[data-pw-match]').forEach(msg => {
    const conf = document.getElementById(msg.dataset.pwMatch);
    const pass = document.getElementById(msg.dataset.pwWith);
    if (!conf || !pass) return;
    msg.setAttribute('aria-live', 'polite');
    const paint = () => {
      if (!conf.value) { msg.hidden = true; return; }
      const same = conf.value === pass.value;
      msg.hidden = false;
      msg.classList.toggle('bad', !same);
      msg.innerHTML = (same ? IC_OK : IC_BAD) + '<span>' + (same ? 'Passwords match' : 'Passwords do not match') + '</span>';
    };
    conf.addEventListener('input', paint);
    pass.addEventListener('input', paint);
    paint();
  });
})();

// Clear the error message as soon as the person starts typing again
document.querySelectorAll('.auth-card input').forEach(i =>
  i.addEventListener('input', () => document.querySelectorAll('.auth-card .alert').forEach(a => a.remove()))
);

// Keep a submit button disabled until every agreement checkbox is ticked.
// Markup: <form data-agree-gate> ... <input type="checkbox" data-agree> ...
//         <p data-agree-hint hidden>...</p> <button type="submit" data-agree-btn disabled>
// (The server still checks the agreements, so this is only a convenience.)
(function () {
  document.querySelectorAll('form[data-agree-gate]').forEach(form => {
    const boxes = [...form.querySelectorAll('input[type=checkbox][data-agree]')];
    const btn = form.querySelector('[data-agree-btn]');
    const hint = form.querySelector('[data-agree-hint]');
    if (!boxes.length || !btn) return;
    const sync = () => {
      const ok = boxes.every(b => b.checked);
      btn.disabled = !ok;
      btn.setAttribute('aria-disabled', ok ? 'false' : 'true');
      if (hint) hint.hidden = ok;
    };
    boxes.forEach(b => b.addEventListener('change', sync));
    window.addEventListener('pageshow', sync);   // browser Back button restores ticked boxes
    sync();
  });
})();
