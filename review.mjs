// Step 2 — apply the rules to the extracted panels, then produce:
//   out/review.json              machine-readable results
//   out/report.html              review dashboard (open in any browser)
//   out/<submittal>-Rev0-REVIEWED.pdf   comment sheet + the submittal stamped and marked up
import fs from 'fs';
import { PDFDocument, StandardFonts, rgb, degrees } from 'pdf-lib';

const cfg = JSON.parse(fs.readFileSync('rules.json', 'utf8'));
const activeRules = cfg.rules.filter(r => r.active !== false);
// Client disclosure: when hidden, names/refs are swapped for aliases and identifying text on the drawings is blacked out.
const settings = fs.existsSync('out/settings.json') ? JSON.parse(fs.readFileSync('out/settings.json', 'utf8')) : {};
const hide = settings.hide ?? cfg.disclosure?.hide ?? false;
const mask = s => hide ? (cfg.disclosure?.aliases || []).reduce((t, [real, alias]) => t.split(real).join(alias), String(s ?? '')) : String(s ?? '');
const ex = JSON.parse(fs.readFileSync('out/extracted.json', 'utf8'));
const panels = Object.values(ex.panels);

// ---------- helpers ----------
const num = s => { const m = String(s ?? '').match(/(\d+(?:\.\d+)?)/); return m ? +m[1] : null; };
const listVals = v => v?.list ? v.list.map(o => o.value) : v?.value ? [v.value] : [];
const parseForm = s => {
  const f = String(s ?? '').match(/FORM-?\s*(\d\w?)/i), t = String(s ?? '').match(/TYPE-?\s*(\d)/i);
  return f ? { form: f[1], type: t ? t[1] : null } : null;
};
const ipNum = s => { const m = String(s ?? '').match(/IP-?\s*(\d\d)/i); return m ? +m[1] : null; };
const firstPage = (p, type) => p.pages.find(x => x.type === type)?.page;

// ---------- rule evaluation ----------
function evaluate(rule, p) {
  const v = p.values;
  const ev = (page, bbox) => ({ page, bbox });
  switch (rule.operator) {
    case 'gte': {
      const vals = listVals(v[rule.attribute]);
      if (!vals.length) return { status: 'unclear', actual: 'not found' };
      const n = ipNum(vals[0]);
      const e = v[rule.attribute].list?.[0];
      return { status: n >= rule.expected ? 'pass' : 'fail', actual: vals[0], evidence: ev(v[rule.attribute].page, e?.bbox) };
    }
    case 'form': {
      const vals = listVals(v.form);
      if (!vals.length) return { status: 'unclear', actual: 'not found' };
      const f = parseForm(vals[0]);
      const e = ev(v.form.page, v.form.list?.[0]?.bbox);
      if (!f) return { status: 'unclear', actual: vals[0], evidence: e };
      if (f.form !== rule.expected.form) return { status: 'fail', actual: vals[0], evidence: e };
      if (!f.type) return { status: 'unclear', actual: vals[0], evidence: e };
      return { status: f.type === rule.expected.type ? 'pass' : 'fail', actual: vals[0], evidence: e };
    }
    case 'minAll': {
      const opts = v.auxWire?.list || [];
      if (!opts.length) return { status: 'unclear', actual: 'not found' };
      const bad = opts.filter(o => num(o.value) < rule.expected);
      const actual = opts.map(o => o.value).join(' / ');
      return bad.length
        ? { status: 'fail', actual: bad.map(o => o.value).join(', '), shown: actual, evidence: ev(v.auxWire.page, bad[0].bbox) }
        : { status: 'pass', actual };
    }
    case 'present': {
      const ok = v.hasHeater && v.hasThermostat;
      const missing = [!v.hasHeater && 'heater', !v.hasThermostat && 'thermostat'].filter(Boolean);
      return { status: ok ? 'pass' : 'fail', actual: ok ? 'heater + thermostat' : 'no ' + missing.join(' / '), evidence: ev(firstPage(p, 'MATERIAL') ?? firstPage(p, 'SLD'), null) };
    }
    case 'consistent': {
      const ips = [v.declared?.value, listVals(v.ip)[0], v.gaIp?.value].map(ipNum).filter(x => x != null);
      const forms = [v.declared?.value, listVals(v.form)[0], v.gaForm?.value].map(s => parseForm(s)?.form).filter(Boolean);
      const same = a => a.every(x => x === a[0]);
      const ok = same(ips) && same(forms);
      return { status: ok ? 'pass' : 'fail', actual: `IP ${[...new Set(ips)].join('/')} · Form ${[...new Set(forms)].join('/')}` };
    }
    case 'noAnomaly': {
      const a = ex.anomalies.filter(x => x.section === p.name);
      if (!a.length) return { status: 'pass', actual: `${p.pages.length} pages` };
      return { status: 'unclear', actual: `p.${a.map(x => x.page).join(', ')} titled ${a[0].titleBlock}`, anomaly: { pages: a.map(x => x.page).join(', '), section: p.name, titleBlock: a[0].titleBlock }, evidence: ev(a[0].page, null) };
    }
  }
}

const results = [];
for (const p of panels) {
  for (const r of activeRules) {
    if (!(r.appliesTo.includes('*') || r.appliesTo.includes(p.kind))) continue;
    results.push({ panel: p.name, kind: p.kind, rule: r.id, ...evaluate(r, p) });
  }
}

// ---------- group into comments ----------
function describePanels(names, kind) {
  const all = panels.filter(p => kind.includes(p.kind)).map(p => p.name);
  if (names.length === all.length && all.length > 3) return `all ${names.length} ${kind.join('/')}s`;
  const missing = all.filter(n => !names.includes(n));
  if (names.length > 6 && missing.length <= 4) return `all ${kind.join('/')}s except ${missing.join(', ')} (${names.length} panels)`;
  return names.join(', ');
}
const ascii = s => s.replace(/≥/g, '>=').replace(/[“”]/g, '"').replace(/[‘’]/g, "'").replace(/—/g, '-');

const comments = [];
for (const l of cfg.linkedSubmittals.filter(s => !s.contractorResponded)) {
  comments.push({
    origin: 'procedural', rule: 'P1',
    text: `${l.subject} (${l.ref}) - ${l.reviewedBy} comments (${l.openComments}) not replied; it is mandatory to respond prior to fabrication of equipment (attached).`,
    clause: 'Linked submittal register', panels: [], pages: [], status: 'fail',
  });
}
for (const r of activeRules) {
  for (const status of ['fail', 'unclear']) {
    const hits = results.filter(x => x.rule === r.id && x.status === status);
    if (!hits.length) continue;
    const names = hits.map(h => h.panel);
    const actual = [...new Set(hits.map(h => h.actual))].join(' / ');
    const a = hits[0].anomaly || {};
    const text = r.comment
      .replaceAll('{actual}', ipNum(actual) != null && r.attribute === 'ip' ? String(ipNum(actual)) : actual)
      .replaceAll('{section}', a.section ?? r.clause.section).replaceAll('{path}', r.clause.path)
      .replaceAll('{panels}', describePanels(names, r.appliesTo.includes('*') ? ['EMDB', 'SMDB', 'DB'] : r.appliesTo))
      .replaceAll('{pages}', a.pages ?? '').replaceAll('{titleBlock}', a.titleBlock ?? '');
    comments.push({
      origin: 'rule', rule: r.id, status, text: ascii(text),
      clause: r.clause.section === '—' ? r.clause.path : `${r.clause.section} › ${r.clause.path}`,
      panels: names, pages: hits.map(h => h.evidence?.page).filter(Boolean),
    });
  }
}
comments.forEach((c, i) => (c.no = i + 1));

const suggested = comments.some(c => c.status === 'fail') ? 'Revise / Resubmit' : comments.length ? 'Approved as noted' : 'Approved';
// engineer's edits from the UI (comments text, manual comments, decision, name) override the draft
const ov = fs.existsSync('out/overrides.json') ? JSON.parse(fs.readFileSync('out/overrides.json', 'utf8')) : null;
const decision = ov?.decision || suggested;
const sheet = (ov?.comments || comments).map((c, i) => ({ ...c, no: i + 1, text: ascii(c.text) }));
const commentFor = (ruleId) => sheet.find(c => c.rule === ruleId)?.no ?? '-';
const isFinal = !!ov?.final;

// what the consultant actually wrote in the reference result (for the side-by-side)
const engineer = [
  { no: 1, text: 'Material Submittal Client Comments not replied and it is mandatory to respond prior to fabrication of Equipment (ATTACHED). SLD Submission Client Concerns need to be marked here for attendance as well.', rule: 'P1' },
  { no: 2, text: 'Contractor to justify not providing ACH and HST as per project requirements.', rule: 'R6' },
  { no: 3, text: 'Vendor to clarify is SMDB Type 2 of Form 2 or not, data in submission not clear.', rule: 'R4' },
  { no: 4, text: 'ESMDB need to be IP54 not 43.', rule: 'R1' },
  { no: '✎', text: 'Hand mark-up on EMDB-A-1 data sheet: "Not accepted" next to auxiliary wiring 1.5 sq.mm.', rule: 'R5' },
];


// ---------- marked-up PDF ----------
const t0 = Date.now();
const src = await PDFDocument.load(fs.readFileSync(ex.source));
const out = await PDFDocument.create();
const font = await out.embedFont(StandardFonts.Helvetica);
const bold = await out.embedFont(StandardFonts.HelveticaBold);
const RED = rgb(0.83, 0.1, 0.1), AMBER = rgb(0.85, 0.47, 0), INK = rgb(0.1, 0.1, 0.12), GREY = rgb(0.45, 0.45, 0.5);

function wrap(text, f, size, width) {
  const lines = []; let line = '';
  for (const w of text.split(' ')) {
    const t = line ? line + ' ' + w : w;
    if (f.widthOfTextAtSize(t, size) > width && line) { lines.push(line); line = w; } else line = t;
  }
  if (line) lines.push(line);
  return lines;
}

// Comment sheet (A4 landscape), may span pages
{
  const W = 842, H = 595, M = 40;
  let page, y;
  const newPage = () => {
    page = out.addPage([W, H]); y = H - M;
    page.drawText('COMMENT SHEET (SUBMITTAL)', { x: M, y: y - 4, size: 16, font: bold, color: INK });
    page.drawText((isFinal ? 'REVIEWED BY ENGINEER' : 'AUTO-GENERATED DRAFT - FOR ENGINEER REVIEW'), { x: W - M - bold.widthOfTextAtSize((isFinal ? 'REVIEWED BY ENGINEER' : 'AUTO-GENERATED DRAFT - FOR ENGINEER REVIEW'), 9), y: y, size: 9, font: bold, color: RED });
    y -= 26;
    for (const [k, v] of [['Project', `${cfg.project.engineerRef} ${cfg.project.name}, ${cfg.project.clientRef}`], ['Submittal No.', `${cfg.project.submittalNo}    Rev. ${cfg.project.revision}`], ['Title', cfg.project.title]]) {
      page.drawText(k + ':', { x: M, y, size: 9, font: bold, color: INK });
      page.drawText(ascii(mask(v)), { x: M + 80, y, size: 9, font, color: INK });
      y -= 14;
    }
    y -= 8;
    page.drawRectangle({ x: M, y: y - 18, width: W - 2 * M, height: 18, color: rgb(0.93, 0.93, 0.95), borderColor: INK, borderWidth: 0.8 });
    page.drawText('NO.', { x: M + 8, y: y - 13, size: 9, font: bold });
    page.drawText("ENGINEER'S COMMENTS", { x: M + 40, y: y - 13, size: 9, font: bold });
    page.drawText('SPEC REF.', { x: M + 470, y: y - 13, size: 9, font: bold });
    page.drawText("CONTRACTOR'S RESPONSE", { x: M + 570, y: y - 13, size: 9, font: bold });
    y -= 18;
  };
  newPage();
  for (const c of sheet) {
    const lines = wrap(ascii(mask(c.text)), font, 9, 420);
    const h = Math.max(lines.length * 12 + 10, 26);
    if (y - h < 90) newPage();
    page.drawRectangle({ x: M, y: y - h, width: W - 2 * M, height: h, borderColor: INK, borderWidth: 0.6 });
    for (const x of [M + 32, M + 462, M + 562]) page.drawLine({ start: { x, y }, end: { x, y: y - h }, thickness: 0.6, color: INK });
    page.drawText(String(c.no), { x: M + 12, y: y - 15, size: 9, font: bold });
    lines.forEach((l, i) => page.drawText(l, { x: M + 40, y: y - 15 - i * 12, size: 9, font, color: INK }));
    wrap(ascii(c.clause || 'Engineer'), font, 8, 90).forEach((l, i) => page.drawText(l, { x: M + 468, y: y - 15 - i * 11, size: 8, font, color: GREY }));
    if (c.status === 'unclear') page.drawText('clarify', { x: M + 468, y: y - h + 5, size: 7, font: bold, color: AMBER });
    y -= h;
  }
  y -= 24;
  if (y < 80) newPage();
  page.drawText(isFinal ? 'ACTION:' : 'SUGGESTED ACTION:', { x: M, y, size: 10, font: bold, color: INK });
  page.drawText(decision.toUpperCase(), { x: M + 110, y, size: 10, font: bold, color: RED });
  page.drawText(ov?.engineer ? `Name: ${ascii(ov.engineer)}     Signature: ____________________     Date: ${new Date().toISOString().slice(0, 10)}` : 'Name: ____________________     Signature: ____________________     Date: ____________', { x: M, y: y - 30, size: 9, font, color: INK });
  page.drawText('Draft generated automatically from the submittal and PART F. The engineer reviews, edits and signs; nothing is issued without human approval.', { x: M, y: 30, size: 7.5, font, color: GREY });
}

// Submittal pages, stamped + marked
const marks = {}; // page -> [{bbox,label,color}]
for (const r of results.filter(x => x.status !== 'pass' && x.evidence?.page)) {
  const rule = cfg.rules.find(x => x.id === r.rule);
  const no = commentFor(r.rule);
  const label = r.status === 'fail'
    ? (rule.markup ? `${rule.markup} - refer Comment ${no}` : `NOT ACCEPTED - refer Comment ${no}`)
    : `CLARIFY - refer Comment ${no}`;
  (marks[r.evidence.page] ||= []).push({ bbox: r.evidence.bbox, label: ascii(label), color: r.status === 'fail' ? RED : AMBER, rule: rule.label });
}

const copied = await out.copyPages(src, src.getPageIndices());
copied.forEach((pg, i) => {
  out.addPage(pg);
  const { width, height } = pg.getSize();
  if (hide) for (const [x0, y0, x1, y1] of ex.pages[i]?.redact || []) {
    pg.drawRectangle({ x: x0, y: y0, width: x1 - x0, height: y1 - y0, color: rgb(0.13, 0.14, 0.16) });
  }
  if (i === 0) {
    const bx = width - 250, by = height - 110;
    pg.drawRectangle({ x: bx, y: by, width: 220, height: 80, borderColor: RED, borderWidth: 2, color: rgb(1, 1, 1), opacity: 0.95 });
    pg.drawText(isFinal ? 'REVIEWED' : 'REVIEWED - DRAFT', { x: bx + 12, y: by + 58, size: 13, font: bold, color: RED });
    pg.drawText(`${isFinal ? 'Action' : 'Suggested'}: ${decision}`, { x: bx + 12, y: by + 40, size: 10, font: bold, color: RED });
    pg.drawText(`${sheet.length} comments - see Comment Sheet`, { x: bx + 12, y: by + 24, size: 9, font, color: RED });
    pg.drawText(new Date().toISOString().slice(0, 10), { x: bx + 12, y: by + 9, size: 9, font, color: RED });
  }
  const list = marks[i + 1] || [];
  let noteY = height - 58;
  for (const m of list) {
    if (m.bbox) {
      const [x0, y0, x1, y1] = m.bbox;
      pg.drawRectangle({ x: x0 - 3, y: y0 - 3, width: x1 - x0 + 6, height: y1 - y0 + 6, borderColor: m.color, borderWidth: 1.8 });
      const tw = bold.widthOfTextAtSize(m.label, 8.5);
      const lx = Math.min(x0 - 3, width - tw - 16), ly = y1 + 6;
      pg.drawRectangle({ x: lx, y: ly - 3, width: tw + 8, height: 13, color: rgb(1, 1, 1), opacity: 0.95, borderColor: m.color, borderWidth: 0.8 });
      pg.drawText(m.label, { x: lx + 4, y: ly, size: 8.5, font: bold, color: m.color });
    } else {
      pg.drawRectangle({ x: 60, y: noteY - 6, width: font.widthOfTextAtSize(`${m.rule}: ${m.label}`, 10) + 16, height: 20, borderColor: m.color, borderWidth: 1.5, color: rgb(1, 1, 1) });
      pg.drawText(`${m.rule}: ${m.label}`, { x: 68, y: noteY, size: 10, font: bold, color: m.color });
      noteY -= 26;
    }
  }
});
const pdfName = `${ascii(mask(cfg.project.submittalNo))}-Rev${cfg.project.revision}-${isFinal ? 'REVIEWED' : 'REVIEWED-DRAFT'}.pdf`;
const sheetPages = out.getPageCount() - src.getPageCount();
fs.writeFileSync('out/' + pdfName, await out.save());
// keep only the current output (an unredacted copy must not linger when details are hidden)
for (const f of fs.readdirSync('out')) if (f.endsWith('.pdf') && f !== pdfName) fs.rmSync('out/' + f);
const markedPages = Object.keys(marks).length;
fs.writeFileSync('out/review.json', JSON.stringify({
  project: cfg.project, rules: cfg.rules, hidden: hide, aliases: cfg.disclosure?.aliases || [], suggested, decision, final: isFinal, engineerName: ov?.engineer || '',
  draftComments: comments, comments: sheet, results, engineer,
  panels: panels.map(p => ({ name: p.name, kind: p.kind, first: p.pages[0].page, last: p.pages[p.pages.length - 1].page, pages: p.pages })),
  anomalies: ex.anomalies, pageCount: ex.pageCount, pdfName, sheetPages,
  marks: Object.fromEntries(Object.entries(marks).map(([k, v]) => [k, v.map(m => ({ label: m.label, rule: m.rule, fail: m.color === RED }))])),
  stats: { panels: panels.length, checks: results.length, fail: results.filter(r => r.status === 'fail').length, unclear: results.filter(r => r.status === 'unclear').length, markedPages },
}, null, 1));

// ---------- HTML report ----------
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const cols = ['R1|R2', 'R3|R4', 'R5', 'R6', 'R7', 'R8'];
const colNames = ['IP rating', 'Form / Type', 'Aux. wiring', 'Heater + thermostat', 'Consistency', 'Drawing set'];
const cell = (panel, ids) => {
  const r = results.find(x => x.panel === panel && ids.split('|').includes(x.rule));
  if (!r) return `<td class="na">—</td>`;
  const pg = r.evidence?.page ? ` · p.${r.evidence.page}` : '';
  return `<td class="${r.status}" title="${esc(r.rule + ': ' + (r.shown || r.actual) + pg)}">${esc(r.shown || r.actual)}</td>`;
};
const counts = s => results.filter(r => r.status === s).length;
const caught = engineer.map(e => {
  const c = comments.find(x => x.rule === e.rule);
  return { ...e, sys: c };
});
const extra = comments.filter(c => !engineer.some(e => e.rule === c.rule));
const kinds = [...new Set(panels.map(p => p.kind))];

const html = `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Submittal Review Demo</title>
<style>
:root{--bg:#f6f6f3;--card:#fff;--ink:#1b1c1f;--mute:#6b6e76;--line:#e3e3de;--pass:#e6f4ea;--passi:#1e6b35;--fail:#fde8e8;--faili:#b3261e;--unc:#fff4e0;--unci:#9a5b00;--acc:#1f4fbf}
@media (prefers-color-scheme:dark){:root:not([data-theme=light]){--bg:#141518;--card:#1d1f23;--ink:#ececec;--mute:#9a9ca3;--line:#2e3036;--pass:#173322;--passi:#8fd6a5;--fail:#3a1a1a;--faili:#ff9d94;--unc:#3a2c12;--unci:#f2c270;--acc:#8fb0ff}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}
.wrap{max-width:1180px;margin:0 auto;padding:28px 16px 60px}
h1{font-size:22px;margin:0 0 4px}h2{font-size:16px;margin:34px 0 10px}.mute{color:var(--mute)}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:16px}
.top{display:grid;grid-template-columns:1fr auto;gap:16px;align-items:start}
.decision{border:2px solid var(--faili);color:var(--faili);border-radius:10px;padding:10px 16px;text-align:center;font-weight:700}
.decision small{display:block;font-weight:500;color:var(--mute)}
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-top:16px}
.kpi b{display:block;font-size:24px}.kpi span{color:var(--mute);font-size:12px}
table{width:100%;border-collapse:collapse;background:var(--card)}th,td{border:1px solid var(--line);padding:7px 9px;text-align:left;vertical-align:top}
th{background:var(--bg);font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:var(--mute)}
.scroll{overflow-x:auto;border-radius:10px;border:1px solid var(--line)}.scroll table{border:0}
td.pass{background:var(--pass);color:var(--passi)}td.fail{background:var(--fail);color:var(--faili);font-weight:600}td.unclear{background:var(--unc);color:var(--unci);font-weight:600}td.na{color:var(--mute)}
.matrix td{font-size:12px;white-space:nowrap}.matrix td:first-child{font-weight:600}
.tag{display:inline-block;font-size:11px;padding:1px 7px;border-radius:99px;font-weight:600}
.tag.fail{background:var(--fail);color:var(--faili)}.tag.unclear{background:var(--unc);color:var(--unci)}.tag.ok{background:var(--pass);color:var(--passi)}.tag.new{background:var(--acc);color:#fff}
.legend span{margin-right:12px}.clause{font-size:12px;color:var(--mute)}
blockquote{margin:4px 0 0;padding-left:10px;border-left:3px solid var(--line);color:var(--mute);font-size:12px}
@media (max-width:700px){.top{grid-template-columns:1fr}}
</style></head><body><div class="wrap">
<div class="top"><div>
<div class="mute">Submittal review · automated draft</div>
<h1>${esc(cfg.project.submittalNo)} · Rev ${cfg.project.revision}</h1>
<div>${esc(cfg.project.title)}</div>
<div class="mute">${esc(cfg.project.name)} · Vendor: ${esc(cfg.project.vendor)} · Checked against ${esc(cfg.project.specDocument)}</div>
</div><div class="decision">${esc(decision)}<small>suggested action</small></div></div>

<div class="kpis">
<div class="card kpi"><b>${ex.pageCount}</b><span>pages read</span></div>
<div class="card kpi"><b>${panels.length}</b><span>panels found (${kinds.map(k => panels.filter(p => p.kind === k).length + ' ' + k).join(', ')})</span></div>
<div class="card kpi"><b>${results.length}</b><span>checks run</span></div>
<div class="card kpi"><b style="color:var(--faili)">${counts('fail')}</b><span>non-compliances</span></div>
<div class="card kpi"><b style="color:var(--unci)">${counts('unclear')}</b><span>need clarification</span></div>
<div class="card kpi"><b>${comments.length}</b><span>comments drafted · ${markedPages} pages marked up</span></div>
</div>

<h2>1 · Comment sheet (auto-drafted)</h2>
<div class="scroll"><table><tr><th>No.</th><th>Engineer's comment (draft)</th><th>Reference</th><th>Evidence</th></tr>
${comments.map(c => `<tr><td><b>${c.no}</b></td><td>${esc(c.text)} ${c.status === 'unclear' ? '<span class="tag unclear">clarify</span>' : ''}</td><td class="clause">${esc(c.clause)}</td><td class="clause">${c.pages.length ? 'p. ' + [...new Set(c.pages)].slice(0, 8).join(', ') + (new Set(c.pages).size > 8 ? ' …' : '') : '—'}</td></tr>`).join('')}
</table></div>

<h2>2 · Does it match the consultant's real review?</h2>
<p class="mute">Left: what the engineer wrote by hand in the reference result (296-page scan, 18/11/2025). Right: what this demo found by itself.</p>
<div class="scroll"><table><tr><th>#</th><th>Engineer's actual comment</th><th>System</th></tr>
${caught.map(e => `<tr><td>${e.no}</td><td>${esc(e.text)}</td><td>${e.sys ? `<span class="tag ok">caught</span> → Comment ${e.sys.no}` : '<span class="tag fail">missed</span>'}</td></tr>`).join('')}
${extra.map(c => `<tr><td>—</td><td class="mute">(not in the engineer's review)</td><td><span class="tag new">extra finding</span> → Comment ${c.no}: ${esc(c.text)}</td></tr>`).join('')}
</table></div>

<h2>3 · Panel-by-panel check</h2>
<div class="legend mute"><span><span class="tag ok">pass</span></span><span><span class="tag fail">fail</span></span><span><span class="tag unclear">clarify</span></span> Hover a cell to see the rule and source page.</div>
<div class="scroll" style="margin-top:8px"><table class="matrix"><tr><th>Panel</th><th>Pages</th>${colNames.map(c => `<th>${c}</th>`).join('')}</tr>
${panels.map(p => `<tr><td>${esc(p.name)}</td><td class="na">${p.pages[0].page}–${p.pages[p.pages.length - 1].page}</td>${cols.map(c => cell(p.name, c)).join('')}</tr>`).join('')}
</table></div>
<p class="mute">DB panels are listed but only the drawing-set check runs on them in this demo; their rules (Section 262416) would be added the same way.</p>

<h2>4 · Rules used (stored as data, editable without code)</h2>
<div class="scroll"><table><tr><th>ID</th><th>Applies to</th><th>Check</th><th>Spec clause</th></tr>
${cfg.rules.map(r => `<tr><td><b>${r.id}</b></td><td>${r.appliesTo.join(', ').replace('*', 'all panels')}</td><td>${esc(r.label)}${r.expected != null ? ` <span class="mute">(${esc(typeof r.expected === 'object' ? 'Form ' + r.expected.form + ' Type ' + r.expected.type : (r.operator === 'gte' ? 'IP ≥ ' : '≥ ') + r.expected + (r.unit ? ' ' + r.unit : ''))})</span>` : ''}${r.note ? `<blockquote>${esc(r.note)}</blockquote>` : ''}</td><td class="clause">${esc(r.clause.section === '—' ? r.clause.path : 'Section ' + r.clause.section + ' › ' + r.clause.path + (r.clause.specPage ? ' (PART F p.' + r.clause.specPage + ')' : ''))}<blockquote>${esc(r.clause.text)}</blockquote></td></tr>`).join('')}
</table></div>

<h2>Output files</h2>
<div class="card"><b>${esc(pdfName)}</b> — ${comments.length > 0 ? 'comment sheet first, then' : ''} the original ${ex.pageCount} pages with a review stamp on page 1 and red/amber boxes drawn around each non-compliant value.<br>
<span class="mute">Demo scope: EMDB / SMDB rules from Section 262300. The engineer reviews, edits and signs; the system only drafts.</span></div>
</div></body></html>`;
fs.writeFileSync('out/report.html', html);

console.log({ decision, comments: comments.length, fail: counts('fail'), unclear: counts('unclear'), markedPages, pdf: pdfName, pdfSeconds: (Date.now() - t0) / 1000 });
comments.forEach(c => console.log(c.no, c.status, '|', c.text));
