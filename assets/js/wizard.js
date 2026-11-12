// Employer registration wizard (works without JS too: all steps just show at once)
(function () {
  const form = document.getElementById('wiz');
  if (!form) return;
  form.classList.add('js');
  const steps = [...form.querySelectorAll('.wiz-step')];
  const dots = [...document.querySelectorAll('.progress li')];
  const back = form.querySelector('[data-back]');
  const next = form.querySelector('[data-next]');
  const submit = form.querySelector('[data-submit]');
  let cur = Math.max(0, Math.min(steps.length - 1, (parseInt(form.dataset.start, 10) || 1) - 1));

  const typeVal = () => { const c = form.querySelector('input[name=type]:checked'); return c ? c.value : ''; };

  // Show only the document fields that match the employer type
  function applyType() {
    const t = typeVal();
    form.querySelectorAll('[data-types]').forEach(g => {
      const on = g.dataset.types.split(' ').includes(t);
      g.hidden = !on;
      g.querySelectorAll('input,select,textarea').forEach(i => { i.disabled = !on; });
    });
    const need = (id, types) => { const el = document.getElementById(id); if (el) el.required = !el.disabled && types.includes(t); };
    need('e-permit', ['registered_business', 'organization']);
    need('e-exp', ['registered_business']);
    need('e-dti', ['registered_business']);
    need('e-permitdoc', ['registered_business', 'organization']);
    need('e-cert', ['individual']);
    document.getElementById('e-photo').required = true;
    ['e-iddoc', 'e-selfie'].forEach(id => { const el = document.getElementById(id); if (el) el.required = true; });
    const label = document.getElementById('permit-label');
    if (label) label.textContent = t === 'organization' ? 'Registration or accreditation number' : "Business (Mayor's) Permit number";
    const dlabel = document.getElementById('permitdoc-label');
    if (dlabel) dlabel.textContent = t === 'organization' ? 'Photo or scan of your registration or accreditation document (SEC or CDA certificate, accreditation letter)' : 'Photo or scan of your Business Permit';
    const plabel = document.getElementById('photo-label');
    if (plabel) plabel.textContent = t === 'organization' ? 'Photo of your office, signage, or the place where you operate' : 'Photo of your storefront, signage, or workplace';
    const hint = (id, txt) => { const el = document.getElementById(id); if (el) el.textContent = txt; };
    hint('permitdoc-hint', t === 'organization' ? 'SEC or CDA certificate, or an accreditation letter. The registration number must be readable.' : 'Make sure the permit number and expiry date can be read.');
    hint('photo-hint', t === 'organization' ? 'Show your office, signboard, or where your group meets.' : 'Show your signage or the front of your place.');
    const nt = document.getElementById('no-type');
    if (nt) nt.style.display = t ? 'none' : 'block';
  }
  form.querySelectorAll('input[name=type]').forEach(r => r.addEventListener('change', applyType));

  let firstShow = true;
  function show(n) {
    cur = n;
    steps.forEach((s, i) => s.classList.toggle('current', i === cur));
    dots.forEach((d, i) => {
      d.classList.toggle('done', i < cur);
      d.classList.toggle('now', i === cur);
    });
    back.style.visibility = cur === 0 ? 'hidden' : 'visible';
    next.style.display = cur === steps.length - 1 ? 'none' : '';
    submit.style.display = cur === steps.length - 1 ? '' : 'none';
    if (cur === 2) {
      const cp = document.getElementById('e-cp'), nm = document.getElementById('e-name');
      if (cp && !cp.value && nm) cp.value = nm.value;
    }
    applyType();
    const top = document.querySelector('.progress');
    if (top && !firstShow) top.scrollIntoView({ behavior: 'smooth', block: 'start' });
    firstShow = false;
  }

  function validStep() {
    const fields = steps[cur].querySelectorAll('input,select,textarea');
    for (const f of fields) {
      if (f.disabled) continue;
      if (!f.checkValidity()) { f.reportValidity(); return false; }
    }
    if (cur === 0) {
      const p = document.getElementById('e-pass'), p2 = document.getElementById('e-pass2');
      if (!/[A-Z]/.test(p.value) || !/[a-z]/.test(p.value) || !/\d/.test(p.value) || !/[^A-Za-z0-9]/.test(p.value)) { p.setCustomValidity('Use uppercase, lowercase, a number and a symbol.'); p.reportValidity(); p.setCustomValidity(''); return false; }
      if (p.value !== p2.value) { p2.setCustomValidity('The two passwords do not match.'); p2.reportValidity(); p2.setCustomValidity(''); return false; }
    }
    return true;
  }

  next.addEventListener('click', () => { if (validStep()) show(cur + 1); });
  back.addEventListener('click', () => show(cur - 1));
  form.addEventListener('submit', e => { if (!validStep()) e.preventDefault(); });

  // File size check (3 MB) and the upload tiles (file name, size, picture preview)
  const fmtSize = b => b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';
  function paint(inp) {
    const tile = inp.closest('.up'); if (!tile) return;
    const f = inp.files && inp.files[0];
    tile.classList.toggle('has-file', !!f);
    const name = tile.querySelector('.up-name'), size = tile.querySelector('.up-size'), th = tile.querySelector('.up-thumb');
    if (name) name.textContent = f ? f.name : '';
    if (size) size.textContent = f ? fmtSize(f.size) : '';
    if (th) {
      if (th._url) { URL.revokeObjectURL(th._url); th._url = null; }
      th.style.backgroundImage = ''; th.classList.remove('img');
      if (f && /^image\//.test(f.type)) { th._url = URL.createObjectURL(f); th.style.backgroundImage = 'url("' + th._url + '")'; th.classList.add('img'); }
    }
  }
  form.querySelectorAll('input[type=file]').forEach(f => f.addEventListener('change', () => {
    if (f.files[0] && f.files[0].size > 3 * 1048576) { (window.ttAlert ? ttAlert('That file is larger than 3 MB. Please choose a smaller file.', {title: 'File too large'}) : alert('That file is larger than 3 MB. Please choose a smaller file.')); f.value = ''; }
    paint(f);
  }));
  form.querySelectorAll('[data-clear]').forEach(b => b.addEventListener('click', e => {
    e.preventDefault();
    const i = b.closest('.up').querySelector('input[type=file]'); i.value = ''; paint(i);
  }));

  // Password strength meter
  const pw = document.getElementById('e-pass'), bar = document.getElementById('e-meter'), txt = document.getElementById('e-meter-text');
  if (pw) pw.addEventListener('input', () => {
    const v = pw.value; let s = 0;
    if (v.length >= 8) s++;
    if (/[A-Z]/.test(v) && /[a-z]/.test(v)) s++;
    if (/\d/.test(v)) s++;
    if (/[^A-Za-z0-9]/.test(v)) s++;
    bar.style.width = (v ? (s + 1) * 20 : 0) + '%';
    bar.style.background = ['#D64545', '#D64545', '#E89B0C', '#2FB463', '#1B7F45'][s];
    txt.textContent = v ? ['Too weak', 'Weak', 'Fair', 'Good', 'Strong'][s] : 'At least 8 characters with uppercase, lowercase, a number and a symbol.';
  });

  show(cur);
})();
