// Live preview and early warnings for the Post a Job form (the server checks again on submit).
(function () {
  const $ = id => document.getElementById(id);
  const f = $('pj-form');
  if (!f) return;
  const types = { full_time: 'Full-time', part_time: 'Part-time', temporary: 'Temporary', internship: 'Internship' };
  const rules = [
    ['a fee or payment from applicants', /\b(?:registration|placement|training|processing|application|membership|reservation|starter\s*kit)\s+fees?\b|\b(?:down\s*payment|pay\s*first|bayad\s*muna|pay\s+to\s+apply|security\s+deposit|deposit)\b|\bsend\s+(?:money|payment|load)\b/i],
    ['a GCash, bank, or account number', /\b(?:gcash|g-cash|paymaya|paypal|bank\s+account|account\s+(?:number|no\.?)|remittance|western\s+union|coins\.ph)\b/i],
    ['overseas or abroad work', /\b(?:abroad|overseas|ofw|visa|dubai|singapore|saudi|qatar|kuwait|taiwan|hong\s*kong|japan|korea|canada|australia|europe|cruise\s+ship)\b/i],
    ['Telegram, WhatsApp, Viber, or shortened links', /(?:t\.me\/|telegram|whatsapp|wa\.me|viber|bit\.ly|tinyurl|linktr\.ee)/i],
    ['asking for an ATM card, PIN, OTP, or ID', /\b(?:atm\s+card|pin\s+code|otp|one[-\s]?time\s+password|send\s+(?:your\s+)?(?:id|selfie|bank))\b/i],
    ['easy or guaranteed income', /\b(?:guaranteed\s+(?:income|salary)|easy\s+money|get\s+rich|earn\s+big|passive\s+income|work\s+from\s+home)\b/i]
  ];
  function peso(n) { return '₱' + Number(n).toLocaleString('en-PH'); }
  function update() {
    $('pv-title').textContent = $('pj-title').value.trim() || 'Your job title';
    const cat = $('pj-cat'); $('pv-cat').textContent = cat.selectedIndex > 0 ? cat.options[cat.selectedIndex].text : 'Category';
    const b = $('pj-brgy'); $('pv-brgy').textContent = b.selectedIndex > 0 ? b.options[b.selectedIndex].text : 'Barangay';
    $('pv-type').textContent = types[$('pj-type').value] || 'Job type';
    const per = $('pj-period').value, mn = parseFloat($('pj-smin').value), mx = parseFloat($('pj-smax').value);
    let sal = 'Negotiable';
    if (per !== 'negotiable' && mn > 0) sal = peso(mn) + (mx > mn ? ' - ' + peso(mx) : '') + ' / ' + per;
    $('pv-salary').textContent = sal;
    $('smin-wrap').style.display = $('smax-wrap').style.display = per === 'negotiable' ? 'none' : '';
    const d = $('pj-deadline').value;
    $('pv-deadline').textContent = d ? 'Apply by ' + new Date(d + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'Apply by (pick a date)';
    const tags = [];
    if ($('pj-student').checked) tags.push('Student-friendly');
    const sched = { weekdays: 'Weekdays only', weekends: 'Weekends only', flexible: 'Flexible hours' }[$('pj-sched').value];
    if (sched) tags.push(sched);
    if ($('pj-resume').value === 'none') tags.push('No resume needed');
    $('pv-tags').innerHTML = tags.map(t => '<span class="tag">' + t + '</span>').join('');
    // Early warnings
    const text = [$('pj-title').value, $('pj-desc').value, $('pj-resp').value, $('pj-qual').value, $('pj-addr').value].join('\n');
    const hits = rules.filter(r => r[1].test(text)).map(r => r[0]);
    if (($('pj-desc').value || '').trim().length > 0 && $('pj-desc').value.trim().length < 80) hits.push('a very short description');
    const w = $('pv-warn');
    if (hits.length) { w.hidden = false; w.querySelector('ul').innerHTML = hits.map(h => '<li>' + h + '</li>').join(''); } else w.hidden = true;
  }
  f.addEventListener('input', update); f.addEventListener('change', update);
  update();
})();