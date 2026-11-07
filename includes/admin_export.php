<?php
// Shared export tools for admin pages: Print, Save as PDF, Save as CSV.
//
// A page builds one $xp array, then calls three things:
//   xp_handle($xp);   before any output  -> sends the CSV when ?export=1 is in the address
//   xp_toolbar($xp);  in the page        -> the Print / Save as PDF / Save as CSV buttons
//   xp_doc($xp);      in the page        -> the print sheet (hidden on screen, used for Print and PDF)
//
// $xp keys: title, slug, purpose, filters [label => value], head [], rows [[]],
//           optional: total [], right [column numbers to right-align], landscape (bool)

require_once __DIR__ . '/helpers.php';

// ===== EDIT HERE: details printed on the header of every report =====
const XP_NAME     = 'Trabaho Tu Bunawan';
const XP_TAGLINE  = 'Local Skills. Local Jobs. Better Futures.';
const XP_ADDRESS  = 'Bunawan, Agusan del Sur, Philippines';   // put the full office address here
const XP_EMAIL    = '';                                         // optional, e.g. 'trabahotubunawan@gmail.com'
const XP_PHONE    = '';                                         // optional, e.g. '0912 345 6789'
const XP_MAX_ROWS = 5000;                                       // most rows one export will include
// =====================================================================

// "Name (Administrator)", or just "Administrator" when that is already the account name
function xp_by(): string {
    $n = trim((string)($_SESSION['name'] ?? 'Administrator'));
    return strcasecmp($n, 'Administrator') === 0 ? 'Administrator' : $n . ' (Administrator)';
}

function xp_filter_pairs(array $f): array {
    $out = [];
    foreach ($f as $k => $v) { $v = trim((string)$v); if ($v !== '') $out[$k] = $v; }
    return $out;
}
function xp_filter_text(array $xp): string {
    $pairs = xp_filter_pairs($xp['filters'] ?? []);
    if (!$pairs) return 'None (all records)';
    $t = [];
    foreach ($pairs as $k => $v) $t[] = $k . ': ' . $v;
    return implode('  |  ', $t);
}
// Stops spreadsheets from running a cell that starts with = + - @ as a formula
function xp_safe($v) { return is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v; }

function xp_icon(string $n): string {
    $p = [
        'print' => '<path d="M6 9V3h12v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M7 14h10v7H7z"/>',
        'pdf'   => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>',
        'csv'   => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M3 15h18M9 4v16"/>',
    ];
    return '<svg class="ico" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$n] ?? '') . '</svg>';
}

// ---------- CSV ----------
// The top block of every CSV file: system name, address, report title, purpose, date, who made it
function xp_csv_intro(callable $w, array $xp): void {
    $count = array_key_exists('count', $xp) ? $xp['count'] : (isset($xp['rows']) ? count($xp['rows']) : null);
    $w([strtoupper(XP_NAME)]);
    $w([XP_TAGLINE]);
    $w([XP_ADDRESS]);
    if (XP_EMAIL !== '' || XP_PHONE !== '') $w([trim(XP_EMAIL . (XP_EMAIL !== '' && XP_PHONE !== '' ? '  |  ' : '') . XP_PHONE)]);
    $w();
    $w([strtoupper($xp['title'])]);
    $w(['Purpose', $xp['purpose'] ?? '']);
    $w([$xp['filters_label'] ?? 'Filters applied', xp_filter_text($xp)]);
    $w(['Date generated', date('F j, Y g:i A')]);
    $w(['Prepared by', xp_by()]);
    if ($count !== null) $w(['Total records', $count]);
}

// Sends the CSV file and stops, but only when the address has ?export=1. Call before any output.
// The Generate report window calls this quietly after a file is made, so the Activity log records it
function xp_log_hit(array $xp): void {
    if (empty($_GET['xplog'])) return;
    $fmt = ['csv' => 'CSV', 'pdf' => 'PDF', 'print' => 'Print'][$_GET['xplog']] ?? 'Export';
    $n = isset($xp['rows']) ? ', ' . count($xp['rows']) . ' records' : '';
    log_admin($xp['log'] ?? ('export_' . $xp['slug']), 'report', null, $xp['title'] . ' (' . $fmt . $n . ')');
    http_response_code(204);
    exit;
}

function xp_handle(array $xp): void {
    xp_log_hit($xp);
    if (empty($_GET['export'])) return;
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="trabaho-tu-bunawan_' . preg_replace('/[^a-z0-9\-]/', '', strtolower($xp['slug'])) . '_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // lets Excel read the file as UTF-8
    $w = function (array $row = []) use ($out) { fputcsv($out, array_map('xp_safe', $row), ',', '"', '\\', "\r\n"); };

    xp_csv_intro($w, $xp);
    $w();
    $w($xp['head']);
    if (!$xp['rows']) $w(['No records match the selected filters.']);
    foreach ($xp['rows'] as $r) $w($r);
    if (!empty($xp['total']) && $xp['rows']) $w($xp['total']);
    $w();
    $w(['End of report']);
    log_admin($xp['log'] ?? ('export_' . $xp['slug']), 'report', null, $xp['title'] . ' (' . count($xp['rows']) . ' records)');
    exit;
}

// ---------- Buttons ----------
// Everything the PDF needs, as plain data for the browser (one section per table)
function xp_pdf_data(array $xp): array {
    $count = array_key_exists('count', $xp) ? $xp['count'] : (isset($xp['rows']) ? count($xp['rows']) : null);
    $sections = $xp['sections'] ?? [['title' => '', 'head' => $xp['head'] ?? [], 'rows' => $xp['rows'] ?? [], 'total' => $xp['total'] ?? null, 'right' => $xp['right'] ?? []]];
    $out = [];
    foreach ($sections as $s) {
        $out[] = ['title' => (string)($s['title'] ?? ''), 'head' => array_values($s['head']),
            'rows' => array_map(fn($r) => array_map(fn($v) => (string)$v, array_values($r)), $s['rows']),
            'total' => !empty($s['total']) ? array_map('strval', array_values($s['total'])) : null, 'right' => array_values($s['right'] ?? [])];
    }
    return [
        'name' => XP_NAME, 'tagline' => XP_TAGLINE, 'contact' => array_values(array_filter([XP_ADDRESS, XP_EMAIL, XP_PHONE])),
        'logo' => url('assets/img/logo-icon.jpg'), 'title' => $xp['title'], 'purpose' => $xp['purpose'] ?? '',
        'filtersLabel' => $xp['filters_label'] ?? 'Filters applied', 'filters' => xp_filter_text($xp),
        'generated' => date('F j, Y') . '  -  ' . date('g:i A'), 'by' => xp_by(), 'byName' => $_SESSION['name'] ?? 'Administrator',
        'count' => $count, 'landscape' => !empty($xp['landscape']), 'choose' => isset($xp['sections']) ? 'sections' : 'columns',
        'file' => 'trabaho-tu-bunawan_' . preg_replace('/[^a-z0-9_\-]/', '', strtolower($xp['slug'])) . '_' . date('Y-m-d'),
        'sections' => $out,
    ];
}

// The Generate report window: choose what to include, preview the paper, then Print / Save as PDF / Save as CSV. Printed once per page.
function xp_script(array $xp): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $json = json_encode(xp_pdf_data($xp), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); ?>
<script type="application/json" id="xpData"><?= $json ?></script>
<template id="xpModalTpl">
  <div class="xp-modal" id="xpModal" role="dialog" aria-modal="true" aria-labelledby="xpModalTitle">
    <div class="xp-modal-box">
      <header class="xp-top">
        <div><h2 id="xpModalTitle">Generate report</h2><p>Choose what to include, check the preview, then print or save.</p></div>
        <button type="button" class="xp-x" data-xp-close aria-label="Close">&times;</button>
      </header>
      <div class="xp-body">
        <aside class="xp-side" id="xpSide"></aside>
        <section class="xp-stage" id="xpStage"><div class="xp-paper-wrap" id="xpWrap"><div class="xp-paper" id="xpPaper"></div></div></section>
      </div>
      <footer class="xp-foot">
        <span class="xp-status" id="xpStatus"></span>
        <div class="xp-foot-btns">
          <button type="button" class="btn btn-outline btn-sm" data-xp-close>Cancel</button>
          <button type="button" class="btn btn-outline btn-sm" data-xp-do="print">Print</button>
          <button type="button" class="btn btn-outline btn-sm" data-xp-do="csv">Save as CSV</button>
          <button type="button" class="btn btn-primary btn-sm" data-xp-do="pdf">Save as PDF</button>
        </div>
      </footer>
    </div>
  </div>
</template>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js" defer></script>
<script>
(function () {
  var D, modal, trigger, logoData = null;
  var state = { pick: [], sign: true, totals: true, land: false };
  var PREVIEW_ROWS = 8;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  // Keeps text the built-in PDF font can show
  function clean(v) {
    return String(v == null ? '' : v).replace(/\u20B1/g, 'PHP ').replace(/[\u2018\u2019]/g, "'").replace(/[\u201C\u201D]/g, '"')
      .replace(/[\u2013\u2014]/g, '-').replace(/[^\x00-\xFF]/g, '?');
  }
  function num(v) { return /^-?\d{4,}$/.test(v) ? Number(v).toLocaleString('en-US') : v; }
  function sig() { return D.byName.toLowerCase() === 'administrator' ? 'Administrator' : D.byName + ', Administrator'; }
  function nameParts() { var p = D.name.split(' '), last = p.pop(); return [p.join(' ') + ' ', last]; }
  function $(id) { return document.getElementById(id); }

  // ---------- What the person ticked -> the tables to show ----------
  function view() {
    var out = [];
    if (D.choose === 'sections') {
      D.sections.forEach(function (s, i) {
        if (state.pick[i]) out.push({ title: s.title, head: s.head, rows: s.rows, total: state.totals ? s.total : null, right: s.right });
      });
    } else {
      var s = D.sections[0], keep = [], map = {};
      s.head.forEach(function (h, i) { if (state.pick[i]) { map[i] = keep.length; keep.push(i); } });
      if (keep.length) out.push({
        title: '', head: keep.map(function (i) { return s.head[i]; }),
        rows: s.rows.map(function (r) { return keep.map(function (i) { return r[i]; }); }),
        total: state.totals && s.total ? keep.map(function (i) { return s.total[i]; }) : null,
        right: s.right.filter(function (i) { return map[i] !== undefined; }).map(function (i) { return map[i]; })
      });
    }
    return out;
  }
  function nothing() { return view().length === 0; }

  // ---------- Preview (the same layout as the printed page) ----------
  function paperHTML(secs, full) {
    var np = nameParts(), h = '';
    h += '<header class="xp-letterhead"><div class="xp-lh-top">' + (D.logo ? '<img class="xp-lh-logo" src="' + esc(D.logo) + '" alt="">' : '') +
      '<div class="xp-lh-brand"><b>' + esc(np[0]) + '<span>' + esc(np[1]) + '</span></b><small>' + esc(D.tagline) + '</small></div>' +
      '<div class="xp-lh-contact">' + D.contact.map(function (c) { return '<span>' + esc(c) + '</span>'; }).join('') + '</div></div>' +
      '<div class="xp-lh-title"><span class="xp-lh-kicker">Official report</span><h1>' + esc(D.title) + '</h1>' + (D.purpose ? '<p><b>Purpose:</b> ' + esc(D.purpose) + '</p>' : '') + '</div>' +
      '<dl class="xp-lh-meta"><div><dt>Date generated</dt><dd>' + esc(D.generated) + '</dd></div><div><dt>Prepared by</dt><dd>' + esc(D.by) + '</dd></div>' +
      (D.count !== null ? '<div><dt>Total records</dt><dd>' + Number(D.count).toLocaleString('en-US') + '</dd></div>' : '') +
      '<div class="xp-wide"><dt>' + esc(D.filtersLabel) + '</dt><dd>' + esc(D.filters) + '</dd></div></dl></header>';
    secs.forEach(function (s) {
      if (s.title) h += '<h2 class="xp-sec-title">' + esc(s.title) + '</h2>';
      var hasNo = s.head[0] === 'No.';
      var cls = function (i) { return (hasNo && i === 0 ? 'xp-no' : '') + (s.right.indexOf(i) !== -1 ? ' xp-r' : ''); };
      h += '<table class="xp-table"><thead><tr>' + s.head.map(function (x, i) { return '<th class="' + cls(i) + '">' + esc(x) + '</th>'; }).join('') + '</tr></thead><tbody>';
      if (!s.rows.length) h += '<tr><td class="xp-empty" colspan="' + s.head.length + '">No records match the selected filters.</td></tr>';
      var rows = full ? s.rows : s.rows.slice(0, PREVIEW_ROWS);
      rows.forEach(function (r) { h += '<tr>' + r.map(function (v, i) { return '<td class="' + cls(i) + '">' + esc(s.right.indexOf(i) !== -1 ? num(v) : v) + '</td>'; }).join('') + '</tr>'; });
      if (!full && s.rows.length > PREVIEW_ROWS) h += '<tr class="xp-more"><td colspan="' + s.head.length + '">&hellip; and ' + (s.rows.length - PREVIEW_ROWS).toLocaleString('en-US') + ' more. All records are included when you print or save.</td></tr>';
      h += '</tbody>';
      if (s.total && s.rows.length) h += '<tfoot><tr>' + s.total.map(function (v, i) { return '<td class="' + (s.right.indexOf(i) !== -1 ? 'xp-r' : '') + '">' + esc(s.right.indexOf(i) !== -1 ? num(v) : v) + '</td>'; }).join('') + '</tr></tfoot>';
      h += '</table>';
    });
    if (!secs.length) h += '<p class="xp-none">Nothing is selected. Tick at least one item on the left.</p>';
    if (state.sign) h += '<div class="xp-sign"><div><span class="xp-line"></span><b>Prepared by</b><small>' + esc(sig()) + '</small></div><div><span class="xp-line"></span><b>Noted by</b><small>Name and signature</small></div></div>';
    h += '<p class="xp-endnote">' + esc(D.name) + ' &middot; ' + esc(D.contact[0] || '') + ' &middot; For internal use only.</p>';
    return h;
  }

  function fit() {
    var paper = $('xpPaper'), wrap = $('xpWrap'), stage = $('xpStage');
    var pw = state.land ? 1123 : 794;
    paper.style.width = pw + 'px';
    var sc = Math.min(1, (stage.clientWidth - 40) / pw);
    paper.style.transform = 'scale(' + sc + ')';
    wrap.style.width = Math.round(pw * sc) + 'px';
    wrap.style.height = Math.round(paper.offsetHeight * sc) + 'px';
  }
  function render() {
    var secs = view();
    $('xpPaper').innerHTML = paperHTML(secs, false);
    var imgs = $('xpPaper').querySelectorAll('img'); for (var i = 0; i < imgs.length; i++) imgs[i].onload = fit;
    fit();
    var none = nothing();
    var btns = modal.querySelectorAll('[data-xp-do]'); for (var j = 0; j < btns.length; j++) btns[j].disabled = none;
    var total = D.choose === 'sections' ? D.sections.length : D.sections[0].head.length, on = state.pick.filter(Boolean).length;
    $('xpStatus').textContent = on + ' of ' + total + ' ' + (D.choose === 'sections' ? 'parts' : 'columns') + ' selected' + (D.count !== null ? '  |  ' + Number(D.count).toLocaleString('en-US') + ' records' : '');
  }

  // ---------- Left panel ----------
  function buildSide() {
    var items = D.choose === 'sections' ? D.sections.map(function (s) { return s.title; }) : D.sections[0].head;
    var hasTotals = D.sections.some(function (s) { return s.total; });
    var h = '<div class="xp-group"><h3>1. What to include</h3><p class="xp-hint">' + (D.choose === 'sections' ? 'Pick the parts of the report you need.' : 'Pick the columns you need. All matching records are included.') + '</p>' +
      '<div class="xp-links"><button type="button" data-xp-all="1">Select all</button><button type="button" data-xp-all="0">Clear</button></div><div class="xp-checks">';
    items.forEach(function (t, i) { h += '<label class="xp-check"><input type="checkbox" data-xp-pick="' + i + '"' + (state.pick[i] ? ' checked' : '') + '><span>' + esc(t) + '</span></label>'; });
    h += '</div></div><div class="xp-group"><h3>2. Page options</h3>' +
      '<label class="xp-check"><input type="checkbox" id="xpSign"' + (state.sign ? ' checked' : '') + '><span>Signature lines (Prepared by / Noted by)</span></label>' +
      (hasTotals ? '<label class="xp-check"><input type="checkbox" id="xpTotals"' + (state.totals ? ' checked' : '') + '><span>Total rows</span></label>' : '') +
      '<div class="xp-orient"><label class="xp-radio"><input type="radio" name="xpOrient" value="p"' + (!state.land ? ' checked' : '') + '><span>Portrait</span></label>' +
      '<label class="xp-radio"><input type="radio" name="xpOrient" value="l"' + (state.land ? ' checked' : '') + '><span>Landscape</span></label></div></div>';
    $('xpSide').innerHTML = h;
  }

  function onModalClick(e) {
    var t = e.target;
    if (t.closest('[data-xp-close]')) { close(); return; }
    var a = t.closest('[data-xp-all]');
    if (a) { var on = a.getAttribute('data-xp-all') === '1'; state.pick = state.pick.map(function () { return on; }); buildSide(); render(); return; }
    var d = t.closest('[data-xp-do]');
    if (d && !d.disabled) run(d.getAttribute('data-xp-do'), d);
  }
  function onModalChange(e) {
    var t = e.target;
    if (t.hasAttribute('data-xp-pick')) state.pick[Number(t.getAttribute('data-xp-pick'))] = t.checked;
    else if (t.id === 'xpSign') state.sign = t.checked;
    else if (t.id === 'xpTotals') state.totals = t.checked;
    else if (t.name === 'xpOrient') state.land = t.value === 'l';
    render();
  }
  function onKey(e) { if (e.key === 'Escape') close(); }

  function open(btn) {
    trigger = btn || null;
    D = JSON.parse($('xpData').textContent);
    var n = D.choose === 'sections' ? D.sections.length : D.sections[0].head.length;
    state.pick = []; for (var i = 0; i < n; i++) state.pick.push(true);
    state.sign = true; state.totals = true; state.land = !!D.landscape;
    if (!modal) {
      modal = document.importNode($('xpModalTpl').content, true).firstElementChild;
      document.body.appendChild(modal);
      modal.addEventListener('click', onModalClick);
      modal.addEventListener('change', onModalChange);
      window.addEventListener('resize', function () { if (modal && !modal.hidden) fit(); });
    }
    modal.hidden = false;
    document.body.classList.add('xp-open');
    document.addEventListener('keydown', onKey);
    buildSide(); render();
    modal.querySelector('.xp-x').focus();
  }
  function close() {
    if (!modal) return;
    modal.hidden = true;
    document.body.classList.remove('xp-open');
    document.removeEventListener('keydown', onKey);
    if (trigger) trigger.focus();
  }

  // ---------- Save ----------
  function logHit(fmt) { try { fetch(location.pathname + '?xplog=' + fmt, { credentials: 'same-origin', keepalive: true }); } catch (e) {} }
  function busy(btn, on) {
    if (!btn) return;
    if (on) { btn.setAttribute('data-label', btn.textContent); btn.textContent = 'Preparing...'; btn.disabled = true; }
    else { btn.textContent = btn.getAttribute('data-label'); btn.disabled = false; }
  }
  function run(kind, btn) {
    if (kind === 'print') return doPrint();
    if (kind === 'csv') return doCsv();
    return doPdf(btn);
  }

  function doPrint() {
    var secs = view(), old = document.title, st = document.createElement('style');
    st.id = 'xpPageStyle'; st.textContent = '@page{size:A4 ' + (state.land ? 'landscape' : 'portrait') + ';margin:12mm 12mm 16mm}';
    document.head.appendChild(st);
    $('xpPaper').innerHTML = paperHTML(secs, true);   // every record, not just the preview rows
    document.title = D.name + ' - ' + D.title + ' - ' + D.file.slice(-10);
    document.body.classList.add('xp-printing');
    var done = function () {
      window.removeEventListener('afterprint', done);
      document.body.classList.remove('xp-printing'); document.title = old;
      var s = $('xpPageStyle'); if (s) s.remove();
      render();
    };
    window.addEventListener('afterprint', done);
    logHit('print');
    setTimeout(function () { window.print(); }, 60);
  }

  function doCsv() {
    var secs = view(), lines = [];
    var cell = function (v) {
      v = String(v == null ? '' : v);
      if (/^[=+\-@]/.test(v)) v = "'" + v;
      return /[",\r\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
    };
    var add = function (row) { lines.push((row || []).map(cell).join(',')); };
    add([D.name.toUpperCase()]); add([D.tagline]); D.contact.forEach(function (c) { add([c]); });
    add(); add([D.title.toUpperCase()]); add(['Purpose', D.purpose]); add([D.filtersLabel, D.filters]);
    add(['Date generated', D.generated]); add(['Prepared by', D.by]);
    if (D.count !== null) add(['Total records', D.count]);
    secs.forEach(function (s) {
      add(); if (s.title) add([s.title.toUpperCase()]);
      add(s.head);
      if (!s.rows.length) add(['No records match the selected filters.']);
      s.rows.forEach(add);
      if (s.total && s.rows.length) add(s.total);
    });
    add(); add(['End of report']);
    var blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = D.file + '.csv';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 2000);
    logHit('csv');
  }

  function loadLogo(src) {
    if (logoData) return Promise.resolve(logoData);
    return fetch(src).then(function (r) { return r.blob(); }).then(function (b) {
      return new Promise(function (ok) { var fr = new FileReader(); fr.onload = function () { logoData = fr.result; ok(logoData); }; fr.readAsDataURL(b); });
    }).catch(function () { return null; });
  }
  function doPdf(btn) {
    if (!window.jspdf || !window.jspdf.jsPDF) {
      alert('The PDF tool could not load. Check your internet connection and try again. You can still use Print and choose "Save as PDF" in the print window.');
      return;
    }
    busy(btn, true);
    loadLogo(D.logo).then(function (logo) {
      try { buildPdf(logo, view()); logHit('pdf'); }
      catch (e) { console.error(e); alert('Sorry, the PDF could not be made. Please try Print instead.'); }
      busy(btn, false);
    });
  }

  function buildPdf(logo, secs) {
    var doc = new window.jspdf.jsPDF({ orientation: state.land ? 'landscape' : 'portrait', unit: 'pt', format: 'a4' });
    var W = doc.internal.pageSize.getWidth(), H = doc.internal.pageSize.getHeight(), M = 40, y = M;
    var FOREST = [15, 61, 46], GREEN = [30, 138, 76], GOLD = [242, 183, 5], SAGE = [238, 244, 240], MUTED = [107, 123, 112], INK = [26, 38, 31], LINE = [213, 223, 216];
    var set = function (fn, c) { doc[fn](c[0], c[1], c[2]); };

    // Header: logo, system name, address
    var tx = M;
    if (logo) { try { doc.addImage(logo, 'JPEG', M, y, 54, 46); tx = M + 64; } catch (e) {} }
    var np = nameParts();
    doc.setFont('helvetica', 'bold'); doc.setFontSize(19); set('setTextColor', FOREST); doc.text(clean(np[0]), tx, y + 20);
    var fw = doc.getTextWidth(clean(np[0] + np[1])) - doc.getTextWidth(clean(np[1])); set('setTextColor', GREEN); doc.text(clean(np[1]), tx + fw, y + 20);
    doc.setFont('helvetica', 'normal'); doc.setFontSize(8.5); set('setTextColor', MUTED); doc.text(clean(D.tagline), tx, y + 35);
    var cy = y + 14;
    D.contact.forEach(function (line) { doc.splitTextToSize(clean(line), 230).forEach(function (l) { doc.text(l, W - M, cy, { align: 'right' }); cy += 11; }); });
    y += 58;
    set('setDrawColor', FOREST); doc.setLineWidth(2.2); doc.line(M, y, W - M, y);
    set('setDrawColor', GOLD); doc.setLineWidth(1); doc.line(M, y + 4, W - M, y + 4);

    // Title and purpose
    y += 24;
    doc.setFont('helvetica', 'bold'); doc.setFontSize(7.5);
    var kw = doc.getTextWidth('OFFICIAL REPORT') + 16;
    set('setFillColor', GOLD); doc.roundedRect(M, y - 9, kw, 14, 7, 7, 'F');
    doc.setTextColor(63, 46, 0); doc.text('OFFICIAL REPORT', M + 8, y + 1);
    y += 26; doc.setFontSize(20); set('setTextColor', FOREST); doc.text(clean(D.title), M, y);
    y += 16; doc.setFont('helvetica', 'normal'); doc.setFontSize(9.5); set('setTextColor', INK);
    if (D.purpose) {
      doc.setFont('helvetica', 'bold'); doc.text('Purpose:', M, y);
      var pw = doc.getTextWidth('Purpose: '); doc.setFont('helvetica', 'normal');
      var rest = doc.splitTextToSize(clean(D.purpose), W - 2 * M - pw);
      doc.text(rest[0] || '', M + pw, y);
      rest = doc.splitTextToSize(rest.slice(1).join(' '), W - 2 * M);
      rest.forEach(function (l, i) { if (l) doc.text(l, M, y + 12 * (i + 1)); });
      y += 12 * (1 + (rest[0] ? rest.length : 0)) + 4;
    }

    // Details box
    y += 4;
    var cols = D.count !== null ? 3 : 2, bw = W - 2 * M, cw = bw / cols;
    var fl = doc.splitTextToSize(clean(D.filters), bw - 20);
    var bh = 34 + 14 + fl.length * 11 + 6;
    set('setFillColor', SAGE); set('setDrawColor', LINE); doc.setLineWidth(0.6); doc.roundedRect(M, y, bw, bh, 5, 5, 'FD');
    var cell = function (x, yy, label, value) {
      doc.setFont('helvetica', 'bold'); doc.setFontSize(6.8); set('setTextColor', MUTED); doc.text(clean(label).toUpperCase(), x, yy);
      doc.setFontSize(9.5); set('setTextColor', INK); doc.text(doc.splitTextToSize(clean(value), cw - 20)[0], x, yy + 12);
    };
    cell(M + 10, y + 14, 'Date generated', D.generated);
    cell(M + cw + 10, y + 14, 'Prepared by', D.by);
    if (D.count !== null) cell(M + 2 * cw + 10, y + 14, 'Total records', Number(D.count).toLocaleString('en-US'));
    set('setDrawColor', LINE); doc.line(M, y + 34, W - M, y + 34);
    doc.setFont('helvetica', 'bold'); doc.setFontSize(6.8); set('setTextColor', MUTED); doc.text(clean(D.filtersLabel).toUpperCase(), M + 10, y + 47);
    doc.setFontSize(9); set('setTextColor', INK);
    fl.forEach(function (l, i) { doc.text(l, M + 10, y + 59 + i * 11); });
    y += bh + 18;

    // Tables (only what was ticked)
    secs.forEach(function (s) {
      if (s.title) {
        if (y > H - 110) { doc.addPage(); y = M + 10; }
        doc.setFont('helvetica', 'bold'); doc.setFontSize(11.5); set('setTextColor', FOREST); doc.text(clean(s.title), M, y); y += 8;
      }
      var colStyles = {};
      s.head.forEach(function (h, i) {
        if (h === 'No.' && i === 0) colStyles[i] = { cellWidth: 28, halign: 'center' };
        else if (s.right.indexOf(i) !== -1) colStyles[i] = { halign: 'right' };
      });
      var fmt = function (r) { return r.map(function (v, i) { return clean(s.right.indexOf(i) !== -1 ? num(v) : v); }); };
      var body = s.rows.length ? s.rows.map(fmt)
        : [[{ content: 'No records match the selected filters.', colSpan: s.head.length, styles: { halign: 'center', textColor: MUTED } }]];
      doc.autoTable({
        startY: y, head: [s.head.map(clean)], body: body, foot: s.total && s.rows.length ? [fmt(s.total)] : undefined,
        margin: { left: M, right: M, top: M + 12, bottom: 46 }, theme: 'plain',
        styles: { font: 'helvetica', fontSize: 8.5, cellPadding: { top: 4.5, bottom: 4.5, left: 5, right: 5 }, textColor: INK, overflow: 'linebreak', lineColor: [220, 229, 223], lineWidth: { bottom: 0.5 } },
        headStyles: { fillColor: FOREST, textColor: [255, 255, 255], fontStyle: 'bold', fontSize: 7.5, lineWidth: 0 },
        footStyles: { fillColor: SAGE, textColor: FOREST, fontStyle: 'bold', lineWidth: 0 },
        alternateRowStyles: { fillColor: [246, 249, 247] },
        columnStyles: colStyles, showHead: 'everyPage', showFoot: 'lastPage', rowPageBreak: 'avoid'
      });
      y = doc.lastAutoTable.finalY + 22;
    });
    if (!secs.length) { doc.setFont('helvetica', 'normal'); doc.setFontSize(10); set('setTextColor', MUTED); doc.text('Nothing was selected.', M, y); y += 20; }

    // Signature lines
    if (state.sign) {
      if (y + 70 > H - 50) { doc.addPage(); y = M + 12; }
      y += 18;
      var sw = (W - 2 * M - 80) / 2;
      [['Prepared by', sig(), M], ['Noted by', 'Name and signature', M + sw + 80]].forEach(function (b) {
        set('setDrawColor', INK); doc.setLineWidth(0.7); doc.line(b[2], y, b[2] + sw, y);
        doc.setFont('helvetica', 'bold'); doc.setFontSize(9.5); set('setTextColor', FOREST); doc.text(b[0], b[2] + sw / 2, y + 13, { align: 'center' });
        doc.setFont('helvetica', 'normal'); doc.setFontSize(8.5); set('setTextColor', MUTED); doc.text(clean(b[1]), b[2] + sw / 2, y + 25, { align: 'center' });
      });
    }

    // Every page: running title after page 1, footer with page numbers
    var n = doc.getNumberOfPages();
    for (var i = 1; i <= n; i++) {
      doc.setPage(i);
      if (i > 1) {
        doc.setFont('helvetica', 'normal'); doc.setFontSize(8); set('setTextColor', MUTED);
        doc.text(clean(D.name + '  |  ' + D.title), M, 26);
        set('setDrawColor', LINE); doc.setLineWidth(0.6); doc.line(M, 32, W - M, 32);
      }
      set('setDrawColor', LINE); doc.setLineWidth(0.6); doc.line(M, H - 34, W - M, H - 34);
      doc.setFont('helvetica', 'normal'); doc.setFontSize(8); set('setTextColor', MUTED);
      doc.text(clean(D.name + '  |  ' + (D.contact[0] || '') + '  |  For internal use only'), M, H - 22);
      doc.text('Page ' + i + ' of ' + n, W - M, H - 22, { align: 'right' });
    }
    doc.save(D.file + '.pdf');
  }

  window.xpOpen = open;
})();
</script>
<?php }

function xp_toolbar(array $xp): void {
    $n = count($xp['rows']);
    $off = $n === 0; ?>
  <div class="xp-bar no-print" role="group" aria-label="Generate a report from this list">
    <p class="xp-bar-info"><b>Report</b><span><?= number_format($n) ?> record<?= $n === 1 ? '' : 's' ?> match your filters<?= $n >= XP_MAX_ROWS ? ' (first ' . number_format(XP_MAX_ROWS) . ' will be included)' : '' ?></span></p>
    <div class="xp-bar-btns">
      <button class="btn btn-primary btn-sm" type="button" onclick="xpOpen(this)" title="Choose what to include, preview it, then print or save"<?= $off ? ' disabled' : '' ?>><?= xp_icon('pdf') ?> Generate report</button>
    </div>
    <small class="xp-bar-tip">Choose what to include and check the preview before you print or save as PDF or CSV.</small>
  </div>
<?php xp_script($xp); }

// ---------- Print sheet ----------
// The header block: logo, system name, address, report title, purpose, date, who made it
function xp_letterhead(array $xp): void {
    $count = array_key_exists('count', $xp) ? $xp['count'] : (isset($xp['rows']) ? count($xp['rows']) : null);
    $contact = array_filter([XP_ADDRESS, XP_EMAIL, XP_PHONE]); ?>
  <header class="xp-letterhead">
    <div class="xp-lh-top">
      <img class="xp-lh-logo" src="<?= e(url('assets/img/logo-icon.jpg')) ?>" alt="" onerror="this.style.display='none'">
      <div class="xp-lh-brand"><b>Trabaho Tu <span>Bunawan</span></b><small><?= e(XP_TAGLINE) ?></small></div>
      <div class="xp-lh-contact"><?php foreach ($contact as $c): ?><span><?= e($c) ?></span><?php endforeach; ?></div>
    </div>
    <div class="xp-lh-title">
      <span class="xp-lh-kicker">Official report</span>
      <h1><?= e($xp['title']) ?></h1>
      <?php if (!empty($xp['purpose'])): ?><p><b>Purpose:</b> <?= e($xp['purpose']) ?></p><?php endif; ?>
    </div>
    <dl class="xp-lh-meta">
      <div><dt>Date generated</dt><dd><?= e(date('F j, Y')) ?> &middot; <?= e(date('g:i A')) ?></dd></div>
      <div><dt>Prepared by</dt><dd><?= e(xp_by()) ?></dd></div>
      <?php if ($count !== null): ?><div><dt>Total records</dt><dd><?= number_format((int)$count) ?></dd></div><?php endif; ?>
      <div class="xp-wide"><dt><?= e($xp['filters_label'] ?? 'Filters applied') ?></dt><dd><?= e(xp_filter_text($xp)) ?></dd></div>
    </dl>
  </header>
<?php }

// Kept so existing pages still work. The Generate report window now builds the paper itself, so nothing is printed here.
function xp_doc(array $xp): void {}