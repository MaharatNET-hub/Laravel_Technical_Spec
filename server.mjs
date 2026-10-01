// Demo web app: serves the UI and runs the extraction / review engine on request.
// Start:  node server.mjs   →  http://localhost:4817
import http from 'http';
import fs from 'fs';
import path from 'path';
import { spawn } from 'child_process';
import { PDFDocument } from 'pdf-lib';

const PORT = process.env.PORT || 4817;
const TYPES = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.mjs': 'text/javascript', '.css': 'text/css', '.pdf': 'application/pdf', '.json': 'application/json', '.svg': 'image/svg+xml' };
const SAMPLE = 'submittal.pdf';
fs.mkdirSync('uploads', { recursive: true });
fs.mkdirSync('out', { recursive: true });

const readJson = (f, d = null) => { try { return JSON.parse(fs.readFileSync(f, 'utf8')); } catch { return d; } };
const body = req => new Promise(res => { const c = []; req.on('data', d => c.push(d)); req.on('end', () => res(Buffer.concat(c))); });
const json = (res, obj, code = 200) => { res.writeHead(code, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(obj)); };

function run(args, onLine) {
  return new Promise((resolve, reject) => {
    const p = spawn(process.execPath, args, { cwd: process.cwd() });
    let buf = '', err = '';
    p.stdout.on('data', d => { buf += d; const lines = buf.split('\n'); buf = lines.pop(); lines.forEach(l => onLine?.(l)); });
    p.stderr.on('data', d => (err += d));
    p.on('close', code => (code === 0 ? resolve() : reject(new Error(err || 'exit ' + code))));
  });
}

function serveFile(res, file) {
  fs.stat(file, (e, st) => {
    if (e || !st.isFile()) { res.writeHead(404); return res.end('not found'); }
    res.writeHead(200, { 'Content-Type': TYPES[path.extname(file)] || 'application/octet-stream', 'Content-Length': st.size, 'Cache-Control': 'no-store' });
    fs.createReadStream(file).pipe(res);
  });
}

// One page of the reviewed PDF at a time, so the viewer doesn't download the whole 20 MB file.
// When client details are hidden, the real names never leave the server (not just hidden on screen).
const state = () => {
  const st = rawState();
  if (!st.hide) return st;
  let text = JSON.stringify({ ...st, aliases: [] });
  for (const [real, alias] of st.aliases) text = text.split(JSON.stringify(real).slice(1, -1)).join(JSON.stringify(alias).slice(1, -1));
  return JSON.parse(text);
};

const pdfCache = { key: null, doc: null };
async function onePage(n) {
  const r = readJson('out/review.json');
  const file = path.join('out', r.pdfName);
  const key = file + fs.statSync(file).mtimeMs;
  if (pdfCache.key !== key) { pdfCache.doc = await PDFDocument.load(fs.readFileSync(file)); pdfCache.key = key; }
  const out = await PDFDocument.create();
  const [pg] = await out.copyPages(pdfCache.doc, [Math.min(Math.max(n, 1), pdfCache.doc.getPageCount()) - 1]);
  out.addPage(pg);
  return Buffer.from(await out.save());
}

const rawState = () => {
  const cfg = readJson('rules.json');
  const review = readJson('out/review.json');
  const ex = readJson('out/extracted.json');
  const settings = readJson('out/settings.json', {});
  return { hide: settings.hide ?? cfg.disclosure?.hide ?? false, aliases: cfg.disclosure?.aliases || [], disclosure: { categories: cfg.disclosure?.categories || [], aliases: cfg.disclosure?.aliases?.length || 0, patterns: cfg.disclosure?.redactPatterns?.length || 0 }, project: cfg.project, rules: cfg.rules, linkedSubmittals: cfg.linkedSubmittals, review, source: ex?.sourceName || null, sample: fs.existsSync(SAMPLE) ? { name: 'CW5.9-DAE-NCC-REM-TS-MEP-002 – Technical Submittal for LV Switchgear Panel GA Drawing.pdf', sizeMb: +(fs.statSync(SAMPLE).size / 1048576).toFixed(1) } : null };
};

// The client's documents are confidential: when DEMO_PASSWORD is set, every request needs it.
const AUTH = process.env.DEMO_PASSWORD
  ? 'Basic ' + Buffer.from(`${process.env.DEMO_USER || 'demo'}:${process.env.DEMO_PASSWORD}`).toString('base64')
  : null;

http.createServer(async (req, res) => {
  if (AUTH && req.headers.authorization !== AUTH) {
    res.writeHead(401, { 'WWW-Authenticate': 'Basic realm="Submittal Review demo", charset="UTF-8"' });
    return res.end('Authentication required');
  }
  const url = new URL(req.url, 'http://x');
  const p = decodeURIComponent(url.pathname);
  try {
    if (p === '/api/state') return json(res, state());

    // Run the full pipeline and stream progress lines back to the browser.
    if (p === '/api/run' && req.method === 'POST') {
      let file = SAMPLE, name = state().sample?.name;
      if (url.searchParams.get('sample') === '1' && !name) return json(res, { error: 'Sample submittal is not on this server' }, 400);
      if (url.searchParams.get('sample') !== '1') {
        const buf = await body(req);
        if (buf.subarray(0, 4).toString() !== '%PDF') return json(res, { error: 'Not a PDF file' }, 400);
        name = url.searchParams.get('name') || 'upload.pdf';
        file = path.join('uploads', 'submittal-upload.pdf');
        fs.writeFileSync(file, buf);
      }
      res.writeHead(200, { 'Content-Type': 'text/plain; charset=utf-8', 'Cache-Control': 'no-store', 'X-Accel-Buffering': 'no' });
      const send = o => res.write(JSON.stringify(o) + '\n');
      fs.rmSync('out/overrides.json', { force: true });
      const t0 = Date.now();
      send({ stage: 'read' });
      await run(['extract.mjs', file], l => {
        const m = l.match(/^progress (\d+)\/(\d+)\s*(.*)$/);
        if (m) send({ stage: 'read', page: +m[1], total: +m[2], panel: m[3] });
      });
      const ex = readJson('out/extracted.json'); ex.sourceName = name; fs.writeFileSync('out/extracted.json', JSON.stringify(ex));
      send({ stage: 'check', panels: Object.keys(ex.panels).length });
      await run(['review.mjs']);
      send({ stage: 'done', seconds: (Date.now() - t0) / 1000 });
      return res.end();
    }

    // Client disclosure on/off: re-build the PDF with or without redaction (keeps the engineer's edits).
    if (p === '/api/settings' && req.method === 'POST') {
      const { hide } = JSON.parse(await body(req));
      fs.writeFileSync('out/settings.json', JSON.stringify({ hide: !!hide }));
      if (fs.existsSync('out/extracted.json')) await run(['review.mjs']);
      return json(res, state());
    }

    // Save rule edits (on/off, thresholds) and re-run the check on the already-extracted data.
    if (p === '/api/rules' && req.method === 'POST') {
      const edits = JSON.parse(await body(req));
      const cfg = readJson('rules.json');
      for (const r of cfg.rules) {
        const e = edits.find(x => x.id === r.id);
        if (!e) continue;
        r.active = e.active;
        if (e.expected !== undefined && typeof r.expected === 'number') r.expected = +e.expected;
      }
      fs.writeFileSync('rules.json', JSON.stringify(cfg, null, 2));
      fs.rmSync('out/overrides.json', { force: true });
      await run(['review.mjs']);
      return json(res, state());
    }

    // Engineer approved: rebuild the PDF with their comments, decision and name.
    if (p === '/api/generate' && req.method === 'POST') {
      const o = JSON.parse(await body(req));
      fs.writeFileSync('out/overrides.json', JSON.stringify({ ...o, final: true }));
      await run(['review.mjs']);
      return json(res, state());
    }

    if (p === '/api/page') {
      const buf = await onePage(+url.searchParams.get('n') || 1);
      res.writeHead(200, { 'Content-Type': 'application/pdf', 'Content-Length': buf.length, 'Cache-Control': 'no-store' });
      return res.end(buf);
    }
    if (p.startsWith('/out/')) {
      const current = readJson('out/review.json')?.pdfName;
      if (path.basename(p) !== current) { res.writeHead(404); return res.end('not found'); }
      return serveFile(res, path.join('out', current));
    }
    if (p.startsWith('/pdfjs/')) return serveFile(res, path.join('node_modules/pdfjs-dist/build', path.basename(p)));
    return serveFile(res, path.join('public', p === '/' ? 'index.html' : path.normalize(p).replace(/^([\\/])+/, '')));
  } catch (e) {
    console.error(e);
    if (!res.headersSent) json(res, { error: String(e.message || e) }, 500); else res.end(JSON.stringify({ stage: 'error', error: String(e.message || e) }) + '\n');
  }
}).listen(PORT, () => console.log(`Submittal Review demo → http://localhost:${PORT}`));
