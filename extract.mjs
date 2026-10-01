// Step 1 — read the vendor submittal PDF and pull per-panel values out of it.
// Output: out/extracted.json
import * as pdfjs from 'pdfjs-dist/legacy/build/pdf.mjs';
import fs from 'fs';

const SRC = process.argv[2] || 'submittal.pdf';
const doc = await pdfjs.getDocument({ data: new Uint8Array(fs.readFileSync(SRC)), verbosity: 0 }).promise;

// Client disclosure: text that identifies the project/client/companies gets a redaction box when "hide" is on.
const cfg = JSON.parse(fs.readFileSync('rules.json', 'utf8'));
const REDACT_RE = new RegExp(cfg.disclosure.redactPatterns.join('|'), 'i');

// Page-space box of a (possibly rotated) text run.
function textBox(it) {
  const [a, b, , , e, f] = it.transform;
  const h = Math.hypot(it.transform[2], it.transform[3]) || 9, len = Math.hypot(a, b) || 1;
  const ux = a / len, uy = b / len;                    // run direction
  const pts = [[0, -0.3 * h], [it.width, -0.3 * h], [0, h], [it.width, h]].map(([u, v]) => [e + u * ux - v * uy, f + u * uy + v * ux]);
  const xs = pts.map(p => p[0]), ys = pts.map(p => p[1]);
  return [Math.min(...xs) - 1, Math.min(...ys) - 1, Math.max(...xs) + 1, Math.max(...ys) + 1];
}

// Page-space boxes of raster images (logos, photos) from the operator list.
async function imageBoxes(page) {
  const { fnArray, argsArray } = await page.getOperatorList();
  const O = pdfjs.OPS, mul = (m, n) => [m[0] * n[0] + m[2] * n[1], m[1] * n[0] + m[3] * n[1], m[0] * n[2] + m[2] * n[3], m[1] * n[2] + m[3] * n[3], m[0] * n[4] + m[2] * n[5] + m[4], m[1] * n[4] + m[3] * n[5] + m[5]];
  let ctm = [1, 0, 0, 1, 0, 0]; const stack = [], boxes = [];
  fnArray.forEach((fn, i) => {
    if (fn === O.save) stack.push(ctm);
    else if (fn === O.restore) ctm = stack.pop() || ctm;
    else if (fn === O.transform) ctm = mul(ctm, argsArray[i]);
    else if (fn === O.paintImageXObject || fn === O.paintInlineImageXObject || fn === O.paintImageMaskXObject) {
      const pts = [[0, 0], [1, 0], [0, 1], [1, 1]].map(([x, y]) => [ctm[0] * x + ctm[2] * y + ctm[4], ctm[1] * x + ctm[3] * y + ctm[5]]);
      const xs = pts.map(p => p[0]), ys = pts.map(p => p[1]);
      if (Math.max(...xs) - Math.min(...xs) > 12 && Math.max(...ys) - Math.min(...ys) > 12) boxes.push([Math.min(...xs), Math.min(...ys), Math.max(...xs), Math.max(...ys)]);
    }
  });
  return boxes;
}

const PANEL_RE = /^(EMDB|ESMDB|SMDB|MDB|DB|FDB|LDB|PDB|MCC)-[A-Z0-9-]+$/;
const norm = s => s.replace(/\s+/g, ' ').trim();

function items(tc) {
  return tc.items
    .filter(it => it.str.trim())
    .map(it => ({ s: norm(it.str), x: it.transform[4], y: it.transform[5], w: it.width, h: Math.abs(it.transform[3]) || 9 }));
}

// Is there a checkbox "X" on the same row as the label?  side: 'right' (label … [X]) or 'left' ([X] label)
function checked(its, label, side, maxGap) {
  const xs = its.filter(i => i.s === 'X' && Math.abs(i.y - label.y) <= 4);
  return xs.find(x => side === 'right'
    ? x.x > label.x + label.w && x.x - (label.x + label.w) <= maxGap
    : x.x < label.x && label.x - (x.x + x.w) <= maxGap);
}

function pickOptions(its, options, side, maxGap) {
  const found = [];
  for (const opt of options) {
    for (const lab of its.filter(i => i.s === opt)) {
      const x = checked(its, lab, side, maxGap);
      if (x) found.push({ value: opt, bbox: [Math.min(lab.x, x.x) - 3, lab.y - 3, Math.max(lab.x + lab.w, x.x + x.w) + 3, lab.y + lab.h + 2] });
    }
  }
  return found;
}

function pageType(text) {
  if (/STANDARD EQUIPMENT TYPE/.test(text)) return 'COVER';
  if (/PANEL LIST/.test(text)) return 'LIST';
  if (/DATA SHEET/.test(text)) return 'DATASHEET';
  if (/MATERIAL LIST|PART LIST|MATERIAL DESC/i.test(text)) return 'MATERIAL';
  if (/SCHEMATIC|SINGLE LINE/i.test(text)) return 'SLD';
  if (/FRONT VIEW|SIDE VIEW|GENERAL ARRANGEMENT|INGRESS PROTECTION/i.test(text)) return 'GA';
  return 'OTHER';
}

function panelName(its) {
  const cands = its.filter(i => PANEL_RE.test(i.s));
  if (!cands.length) return null;
  const label = its.find(i => i.s === 'PANEL NAME');
  if (!label) return cands[0].s;
  cands.sort((a, b) => Math.hypot(a.x - label.x, a.y - label.y) - Math.hypot(b.x - label.x, b.y - label.y));
  return cands[0].s;
}

const pages = [];
for (let p = 1; p <= doc.numPages; p++) {
  const page = await doc.getPage(p);
  const tc = await page.getTextContent();
  const its = items(tc);
  const text = its.map(i => i.s).join(' ');
  const rec = { page: p, type: pageType(text), panel: panelName(its), values: {} };

  if (rec.type === 'COVER') {
    // the declared type is split into several text runs on one row ("FORM-" "4" "," "TYPE-6," "IP-4" "3")
    const lab = its.find(i => i.s === 'STANDARD EQUIPMENT TYPE');
    const first = lab && its.filter(i => /^FORM-|^IP-?\d/.test(i.s) && i.y < lab.y).sort((a, b) => b.y - a.y)[0];
    if (first) {
      const row = its.filter(i => Math.abs(i.y - first.y) < 2 && i.x >= lab.x - 20).sort((a, b) => a.x - b.x);
      const value = row.map(i => i.s).join('').replace(/,(?=\S)/g, ', ');
      const last = row[row.length - 1];
      rec.values.declared = { value, bbox: [row[0].x - 3, first.y - 3, last.x + last.w + 3, first.y + first.h + 2] };
    }
  }

  if (rec.type === 'DATASHEET') {
    const ip = pickOptions(its, ['IP-43', 'IP-54', 'IP-65'], 'right', 220);
    const form = pickOptions(its, ['FORM-1', 'FORM-2', 'FORM-3B', 'FORM-4, TYPE-6'], 'right', 220);
    const make = pickOptions(its, ['EATON', 'EATON (xENERGY)', 'SCHNEIDER', 'ABB', 'SIEMENS'], 'right', 220);
    const bus = pickOptions(its, ['COPPER', 'ALUM.'], 'left', 30);
    const tin = pickOptions(its, ['TIN PLATED'], 'left', 30);
    // aux wiring sizes: "[X] 1.5 SQ.MM"
    const wires = pickOptions(its, ['1.5', '2.5', '4', '6'], 'left', 30)
      .filter(w => its.some(i => i.s.startsWith('SQ.MM') && Math.abs(i.y - w.bbox[1] - 3) <= 4));
    const icw = its.find(i => /^Icw\s*=/.test(i.s));
    if (ip.length) rec.values.ip = ip;
    if (form.length) rec.values.form = form;
    if (make.length) rec.values.make = make;
    if (bus.length) rec.values.busbar = bus;
    rec.values.tinPlated = tin.length > 0;
    if (wires.length) rec.values.auxWire = wires;
    if (icw) rec.values.icw = { value: icw.s, bbox: [icw.x - 3, icw.y - 3, icw.x + icw.w + 3, icw.y + icw.h + 2] };
  }

  if (rec.type === 'MATERIAL' || rec.type === 'SLD') {
    rec.values.hasHeater = /HEATER/i.test(text);
    rec.values.hasThermostat = /THERMOSTAT|HYGROSTAT/i.test(text);
  }

  if (rec.type === 'GA') {
    const m = text.match(/TYPE\s*:\s*(FORM-?\s*\d[^)]*?)(?=\s+[a-z]\)|\s{2}|$)/i);
    const ipm = text.match(/INGRESS PROTECTION\s*:\s*(IP-?\s*\d\d)/i);
    if (m) rec.values.gaForm = { value: norm(m[1]) };
    if (ipm) rec.values.gaIp = { value: ipm[1].replace(/\s/g, '') };
  }
  rec.redact = [...tc.items.filter(it => it.str.trim() && REDACT_RE.test(it.str)).map(textBox), ...await imageBoxes(page)].map(b => b.map(v => +v.toFixed(1)));
  pages.push(rec);
  if (p % 4 === 0 || p === doc.numPages) console.log(`progress ${p}/${doc.numPages} ${rec.panel || ''}`);
}

// Group pages into panels (a panel runs from its cover sheet until the next one).
const panels = {};
const anomalies = [];
let current = null;
for (const pg of pages) {
  if (pg.type === 'COVER' && pg.panel) current = pg.panel;
  if (pg.type === 'LIST' || (pg.type === 'OTHER' && !pg.panel)) { current = null; continue; } // section divider
  const name = pg.type === 'OTHER' && !current ? null : (pg.panel || current);
  if (!name) continue;
  if (current && pg.panel && pg.panel !== current) {
    anomalies.push({ page: pg.page, section: current, titleBlock: pg.panel, type: pg.type });
  }
  const P = panels[name] ||= { name, kind: name.split('-')[0], pages: [], values: {} };
  P.pages.push({ page: pg.page, type: pg.type });
  for (const [k, v] of Object.entries(pg.values)) {
    if (k === 'hasHeater' || k === 'hasThermostat') P.values[k] = P.values[k] || v;
    else if (k === 'tinPlated') P.values[k] = P.values[k] || v;
    else P.values[k] ??= { ...(typeof v === 'object' && !Array.isArray(v) ? v : { list: v }), page: pg.page };
  }
}

fs.mkdirSync('out', { recursive: true });
fs.writeFileSync('out/extracted.json', JSON.stringify({ source: SRC, pageCount: doc.numPages, pages, panels, anomalies }, null, 1));
const types = pages.reduce((a, p) => (a[p.type] = (a[p.type] || 0) + 1, a), {});
console.log('pages', doc.numPages, types, 'panels', Object.keys(panels).length);
