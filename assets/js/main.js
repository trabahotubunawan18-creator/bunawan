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

// Show / hide password
document.querySelectorAll('[data-toggle-pw]').forEach(btn => btn.addEventListener('click', () => {
  const input = document.getElementById(btn.dataset.togglePw);
  const show = input.type === 'password';
  input.type = show ? 'text' : 'password';
  btn.textContent = show ? 'Hide' : 'Show';
}));

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
