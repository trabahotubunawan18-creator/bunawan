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

// Password strength meter
const rp = document.getElementById('r-pass');
if (rp) {
  const bar = document.getElementById('meter-bar');
  const txt = document.getElementById('meter-text');
  rp.addEventListener('input', () => {
    const v = rp.value;
    let s = 0;
    if (v.length >= 8) s++;
    if (/[A-Z]/.test(v) && /[a-z]/.test(v)) s++;
    if (/\d/.test(v)) s++;
    if (/[^A-Za-z0-9]/.test(v)) s++;
    const labels = ['Too weak', 'Weak', 'Fair', 'Good', 'Strong'];
    const colors = ['#D64545', '#D64545', '#E89B0C', '#2FB463', '#1B7F45'];
    bar.style.width = (v ? (s + 1) * 20 : 0) + '%';
    bar.style.background = colors[s];
    txt.textContent = v ? labels[s] : 'At least 8 characters with uppercase, lowercase, a number and a symbol.';
  });
}

// Clear the error message as soon as the person starts typing again
document.querySelectorAll('.auth-card input').forEach(i =>
  i.addEventListener('input', () => document.querySelectorAll('.auth-card .alert').forEach(a => a.remove()))
);
