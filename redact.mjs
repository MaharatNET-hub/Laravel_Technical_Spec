// True redaction for client disclosure: a black box drawn over a name still leaves the text in the
// PDF (copy / search / Ctrl+A reveals it). This removes the text-show and image operators that sit
// inside the redaction boxes from the page's content streams (and the form XObjects it uses).
import { PDFName, PDFArray, PDFRawStream, PDFDict, decodePDFRawStream } from 'pdf-lib';

const WS = new Set([0, 9, 10, 12, 13, 32]);
const DELIM = new Set('()<>[]{}/%'.split('').map(c => c.charCodeAt(0)));

// Tokenise a content stream into operands/operators, keeping each token's byte range.
function tokens(b) {
  const out = [];
  let i = 0;
  const n = b.length;
  while (i < n) {
    const c = b[i];
    if (WS.has(c)) { i++; continue; }
    const s = i;
    if (c === 37) { while (i < n && b[i] !== 10 && b[i] !== 13) i++; continue; } // % comment
    if (c === 40) { // (string)
      let depth = 0;
      for (; i < n; i++) {
        if (b[i] === 92) { i++; continue; }
        if (b[i] === 40) depth++;
        else if (b[i] === 41 && --depth === 0) { i++; break; }
      }
      out.push({ t: 'str', s, e: i }); continue;
    }
    if (c === 60 && b[i + 1] === 60) { out.push({ t: '<<', s, e: i += 2 }); continue; }
    if (c === 62 && b[i + 1] === 62) { out.push({ t: '>>', s, e: i += 2 }); continue; }
    if (c === 60) { while (i < n && b[i] !== 62) i++; out.push({ t: 'str', s, e: ++i }); continue; } // <hex>
    if (c === 91 || c === 93) { out.push({ t: c === 91 ? '[' : ']', s, e: ++i }); continue; }
    if (c === 47) { i++; while (i < n && !WS.has(b[i]) && !DELIM.has(b[i])) i++; out.push({ t: 'name', s, e: i, v: Buffer.from(b.subarray(s + 1, i)).toString('latin1') }); continue; }
    while (i < n && !WS.has(b[i]) && !DELIM.has(b[i])) i++;
    if (i === s) { i++; continue; }
    const v = Buffer.from(b.subarray(s, i)).toString('latin1');
    if (/^[+-]?(\d+\.?\d*|\.\d+)$/.test(v)) { out.push({ t: 'num', s, e: i, v: +v }); continue; }
    out.push({ t: 'op', s, e: i, v });
    if (v === 'ID') { // inline image data runs until whitespace + EI + whitespace
      let j = i + 1;
      while (j < n - 2 && !(WS.has(b[j - 1]) && b[j] === 69 && b[j + 1] === 73 && (j + 2 >= n || WS.has(b[j + 2])))) j++;
      out.push({ t: 'op', s: j, e: j + 2, v: 'EI', dataStart: i }); i = j + 2;
    }
  }
  return out;
}

const mul = (m, n) => [m[0] * n[0] + m[1] * n[2], m[0] * n[1] + m[1] * n[3], m[2] * n[0] + m[3] * n[2], m[2] * n[1] + m[3] * n[3], m[4] * n[0] + m[5] * n[2] + n[4], m[4] * n[1] + m[5] * n[3] + n[5]];
const apply = (m, x, y) => [m[0] * x + m[2] * y + m[4], m[1] * x + m[3] * y + m[5]];
const inside = (boxes, [x, y], pad = 2) => boxes.some(([x0, y0, x1, y1]) => x >= x0 - pad && x <= x1 + pad && y >= y0 - pad && y <= y1 + pad);
const overlaps = (boxes, a) => boxes.some(b => a[0] < b[2] && a[2] > b[0] && a[1] < b[3] && a[3] > b[1]);

function streamBytes(obj) {
  if (obj instanceof PDFRawStream) return decodePDFRawStream(obj).decode();
  return obj.getContents?.() ?? new Uint8Array();
}

// Returns the content with redacted operators removed. ctm0 = matrix the stream is drawn with.
function scrubStream(bytes, boxes, ctm0, resources, ctx, seen, stats) {
  const tk = tokens(bytes);
  const cut = []; // [start,end) byte ranges to blank out
  let biStart = 0, ctm = ctm0, stack = [], tm = [1, 0, 0, 1, 0, 0], tlm = tm, lead = 0, ops = [], opStart = null;
  const xobjs = resources?.lookup?.(PDFName.of('XObject'), PDFDict);
  for (const t of tk) {
    if (t.t !== 'op') { if (opStart == null) opStart = t.s; ops.push(t); continue; }
    const a = ops.map(o => o.v), s0 = opStart ?? t.s;
    const drop = () => cut.push([s0, t.e]);
    const textAt = () => inside(boxes, apply(mul(tm, ctm), 0, 0));
    switch (t.v) {
      case 'BI': biStart = t.s; break;
      case 'q': stack.push(ctm); break;
      case 'Q': ctm = stack.pop() || ctm; break;
      case 'cm': if (a.length === 6) ctm = mul(a, ctm); break;
      case 'BT': tm = tlm = [1, 0, 0, 1, 0, 0]; break;
      case 'Tm': if (a.length === 6) tm = tlm = a; break;
      case 'Td': tm = tlm = mul([1, 0, 0, 1, a[0], a[1]], tlm); break;
      case 'TD': lead = -a[1]; tm = tlm = mul([1, 0, 0, 1, a[0], a[1]], tlm); break;
      case 'TL': lead = a[0]; break;
      case 'T*': tm = tlm = mul([1, 0, 0, 1, 0, -lead], tlm); break;
      case "'": case '"': tm = tlm = mul([1, 0, 0, 1, 0, -lead], tlm); if (textAt()) { drop(); stats.text++; } break;
      case 'Tj': case 'TJ': if (textAt()) { drop(); stats.text++; } break;
      case 'EI': { // inline image: unit square through the CTM
        const pts = [[0, 0], [1, 0], [0, 1], [1, 1]].map(([x, y]) => apply(ctm, x, y));
        const bb = [Math.min(...pts.map(p => p[0])), Math.min(...pts.map(p => p[1])), Math.max(...pts.map(p => p[0])), Math.max(...pts.map(p => p[1]))];
        if (overlaps(boxes, bb)) { cut.push([biStart, t.e]); stats.images++; }
        break;
      }
      case 'Do': {
        const name = ops[0]?.v, ref = name && xobjs?.get(PDFName.of(name));
        const xo = ref && ctx.lookup(ref);
        if (!(xo instanceof PDFRawStream)) break;
        const sub = xo.dict.get(PDFName.of('Subtype'))?.toString();
        if (sub === '/Image') {
          const pts = [[0, 0], [1, 0], [0, 1], [1, 1]].map(([x, y]) => apply(ctm, x, y));
          const bb = [Math.min(...pts.map(p => p[0])), Math.min(...pts.map(p => p[1])), Math.max(...pts.map(p => p[0])), Math.max(...pts.map(p => p[1]))];
          if (overlaps(boxes, bb)) { drop(); stats.images++; }
        } else if (sub === '/Form' && !seen.has(ref.toString())) {
          seen.add(ref.toString());
          const m = xo.dict.lookup(PDFName.of('Matrix'), PDFArray)?.asArray().map(x => x.asNumber?.() ?? 0) || [1, 0, 0, 1, 0, 0];
          const res = xo.dict.lookup(PDFName.of('Resources'), PDFDict) || resources;
          const clean = scrubStream(streamBytes(xo), boxes, mul(m, ctm), res, ctx, seen, stats);
          if (clean) {
            const dict = xo.dict.clone(ctx);
            for (const k of ['Filter', 'DecodeParms', 'Length']) dict.delete(PDFName.of(k));
            ctx.assign(ref, ctx.flateStream(clean, Object.fromEntries([...dict.entries()].map(([k, v]) => [k.asString().slice(1), v]))));
          }
        }
        break;
      }
    }
    ops = []; opStart = null;
  }
  if (!cut.length) return null;
  const outB = Buffer.from(bytes);
  for (const [s, e] of cut) outB.fill(32, s, e); // spaces keep every other byte offset intact
  return outB;
}

// Redact one pdf-lib page in place. Returns counts of removed text runs / images.
export function redactPage(doc, page, boxes) {
  const stats = { text: 0, images: 0 };
  if (!boxes?.length) return stats;
  const ctx = doc.context, node = page.node;
  const contents = node.Contents();
  if (!contents) return stats;
  const parts = contents instanceof PDFArray ? contents.asArray().map(r => ctx.lookup(r)) : [contents];
  // concatenate so q/Q and BT/ET state carries across split streams
  const all = Buffer.concat(parts.map(p => Buffer.concat([Buffer.from(streamBytes(p)), Buffer.from('\n')])));
  const clean = scrubStream(all, boxes, [1, 0, 0, 1, 0, 0], node.Resources(), ctx, new Set(), stats);
  if (clean) node.set(PDFName.of('Contents'), ctx.register(ctx.flateStream(clean)));
  return stats;
}

// Strip document-level metadata that can carry the real project / company names.
export function scrubInfo(doc) {
  doc.setTitle(''); doc.setAuthor(''); doc.setSubject(''); doc.setKeywords([]); doc.setCreator('Submittal Review Assistant');
  doc.catalog.delete(PDFName.of('Metadata'));
}
