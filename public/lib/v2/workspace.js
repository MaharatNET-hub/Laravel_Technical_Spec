// v2 admin: the v1 analysis tool, bound to one submission (generated from public/app.js — keep the two in step).
import * as pdfjs from '../pdfjs/pdf.min.js';
pdfjs.GlobalWorkerOptions.workerSrc = new URL('../pdfjs/pdf.worker.min.js', import.meta.url).href;
const STD_FONTS = new URL('../pdfjs/standard_fonts/', import.meta.url).href;

// All URLs are relative to where the app is installed (works in a sub-folder too).
const url = p => `${window.APP.base}/${p}`;
const api = async (p, body, opts = {}) => {
  const res = await fetch(url('api/' + p), { method: body === undefined ? 'GET' : 'POST', headers: { 'X-CSRF-TOKEN': window.APP.csrf, 'Accept': 'application/json', ...(body instanceof Blob ? {} : { 'Content-Type': 'application/json' }) }, body: body === undefined ? undefined : body instanceof Blob ? body : JSON.stringify(body), ...opts });
  const data = await res.json().catch(() => ({ error: res.status === 413 ? 'File part too large for this server' : `Server error ${res.status}` }));
  if (!res.ok) throw new Error(data.error || res.statusText);
  return data;
};
const outUrl = r => url(`out/${encodeURIComponent(r.pdfName)}?t=${S.stamp}`);

const $ = (s, el = document) => el.querySelector(s);
const mask = s => S.hide ? S.aliases.reduce((t, [real, alias]) => t.split(real).join(alias), String(s ?? '')) : String(s ?? '');
const esc = s => mask(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const I = {
  upload: '<svg class="i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4m0 0-4 4m4-4 4 4"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/></svg>',
  check: '<svg class="i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg>',
  eye: '<svg class="i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>',
  trash: '<svg class="i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>',
  plus: '<svg class="i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>',
  file: '<svg class="i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8z"/><path d="M14 3v5h5"/></svg>',
  down: '<svg class="i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v12m0 0 4-4m-4 4-4-4M4 20h16"/></svg>',
  left: '<svg class="i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m15 6-6 6 6 6"/></svg>',
  right: '<svg class="i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m9 6 6 6-6 6"/></svg>',
  undo: '<svg class="i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14 4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-3"/></svg>',
};
const DECISIONS = ['Approved', 'Approved as noted', 'Revise / Resubmit', 'Rejected', 'Approved as noted / Resubmit', 'No action required / for information only'];
const COLS = [['R1', 'R2'], ['R3', 'R4'], ['R5'], ['R6'], ['R7'], ['R8']];
const COLNAMES = ['IP rating', 'Form / Type', 'Aux. wiring', 'Heater + thermostat', 'Consistency', 'Drawing set'];

const S = { hide: true, aliases: [], data: null, view: 'home', tab: 'comments', comments: [], decision: '', engineer: '', filter: { kind: 'All', issues: false, q: '' }, page: null, zoom: 1.35, pdf: null, pdfUrl: null, stamp: Date.now() };

async function load() {
  S.data = await api('state');
  S.hide = S.data.hide; S.aliases = S.data.aliases;
  const r = S.data.review;
  if (r) {
    S.comments = r.comments.map(c => ({ ...c, orig: r.draftComments.find(d => d.rule === c.rule && d.status === c.status)?.text }));
    S.decision = r.decision;
    S.engineer = r.engineerName || '';
  }
}

function toast(html, ms = 3500) {
  const t = $('#toast'); t.innerHTML = html; t.hidden = false;
  clearTimeout(toast.t); toast.t = setTimeout(() => (t.hidden = true), ms);
}

function disclosureSwitch() {
  if (S.data.locked) { $('.user').innerHTML = `<span class="disc" title="This installation always hides client details"><span><b>Client disclosure</b><small>Details hidden (locked)</small></span></span>`; return; }
  $('.user').innerHTML = `<label class="disc" title="Hide the project, client and company names on screen and black them out on the drawings">
    <span class="switch"><input type="checkbox" id="hide" ${S.hide ? 'checked' : ''}><span></span></span>
    <span><b>Client disclosure</b><small>${S.hide ? 'Details hidden' : 'Details visible'}</small></span></label>`;
}

function disclosureCard() {
  const d = S.data.disclosure || {}, red = S.data.review?.redacted;
  return `<div class="card pad disc-card ${S.hide ? 'on' : ''}">
    <div class="label">Client disclosure</div>
    <div class="disc-state"><span class="chip ${S.hide ? 'ok' : 'warn'}"><span class="dot"></span>${S.hide ? 'Client details hidden' : 'Client details visible'}</span></div>
    <div class="mute" style="font-size:12.5px;margin:6px 0">${S.hide
      ? 'Names are replaced with neutral labels on screen and in every generated page. On the drawings the text and logos are removed from the PDF itself, not just covered.'
      : 'Real names are shown. Turn on before showing the demo or sharing the PDF with anyone outside the project.'}</div>
    <ul class="disc-list">${(d.categories || []).map(c => `<li>${esc(c)}</li>`).join('')}</ul>
    <div class="mute" style="font-size:12px">${d.aliases} names mapped to labels · ${d.patterns} redaction patterns${S.hide && red ? ` · last PDF: ${red.text} text runs and ${red.images} logos removed` : ''}</div>
  </div>`;
}

function crumbs() {
  const p = S.data.project;
  const parts = [`<a href="${window.APP.back}">← ${esc(window.APP.code)}</a>`, `<span>›</span><span>${esc(mask(p.name))}</span>`];
  if (S.view === 'proc') parts.push('<span>›</span><span class="cur">Processing…</span>');
  if (S.view === 'ws') parts.push(`<span>›</span><span class="cur">${esc(p.submittalNo)} · Rev ${p.revision}</span>`);
  $('#crumbs').innerHTML = parts.join(' ');
}

function render() {
  crumbs();
  disclosureSwitch();
  const h = S.view === 'ws' ? '#ws/' + S.tab : S.view === 'home' ? '#' : location.hash;
  if (location.hash !== h) history.replaceState(null, '', h || '#');
  const v = $('#view');
  if (S.view === 'home') v.innerHTML = homeView();
  if (S.view === 'proc') v.innerHTML = procView();
  if (S.view === 'ws') { v.innerHTML = wsView(); if (S.tab === 'drawings') drawPage(); }
  window.scrollTo({ top: 0 });
}

/* ---------------- home ---------------- */
function homeView() {
  const sub = window.APP.sub;
  return `<div class="proc card pad">
    <div class="label">Submission ${esc(window.APP.code)}</div>
    <h1 style="margin-top:4px">Not analysed yet</h1>
    <div class="mute" style="margin:6px 0 18px">${esc(sub.file)} · ${esc(sub.size)} — the assistant reads every page, checks it against the rules and drafts the comment sheet.</div>
    <button class="btn primary" type="button" data-run-sample>${I.check}Run analysis</button>
    <a class="btn" href="${window.APP.back}" style="margin-inline-start:8px">Back</a>
  </div>`;
}

/* ---------------- processing ---------------- */
const STEPS = [
  ['Receiving file', 'Upload and validate the PDF'],
  ['Reading pages', 'Text + coordinates from every page'],
  ['Identifying panels & extracting values', 'Title blocks, data-sheet check boxes, part lists'],
  ['Checking against PART F', 'Applying active rules from Section 262300'],
  ['Drafting comments & marking up the PDF', 'Grouping findings, stamping, boxing non-compliant values'],
];
const P = { step: 0, pct: 0, detail: '' };
function procView() {
  return `<div class="proc card pad">
    <div class="label">Processing</div>
    <h1 style="margin-top:4px">Reviewing submittal…</h1>
    <div class="bar"><i id="pbar" style="width:${P.pct}%"></i></div>
    <div class="mute" id="pdetail" style="font-size:12.5px">${esc(P.detail)}</div>
    <ul class="steps">${STEPS.map((s, i) => `<li class="${i < P.step ? 'done' : i === P.step ? 'active' : ''}"><span class="st">${i < P.step ? I.check : ''}</span><div><b>${s[0]}</b><small>${s[1]}</small></div></li>`).join('')}</ul>
  </div>`;
}
function setProc(step, pct, detail) {
  Object.assign(P, { step, pct, detail });
  if (S.view === 'proc') { $('#view').innerHTML = procView(); }
}

// The work runs in short requests (shared hosts stop long ones): upload in chunks, then read pages in batches.
async function runReview(file) {
  S.view = 'proc'; Object.assign(P, { step: 0, pct: 2, detail: file ? file.name : 'Sample submittal' }); render();
  const t0 = Date.now();
  try {
    const job = await api('start', {});
    setProc(1, 5, `Opening document · ${job.total} pages`);
    let lastPanel = '';
    for (let guard = 0; guard < 2000; guard++) {
      const m = await api('step', {});
      if (m.busy) await new Promise(r => setTimeout(r, 1500));
      if (m.panel) lastPanel = m.panel;
      setProc(m.page / m.total > .15 ? 2 : 1, 5 + 60 * m.page / m.total, `Page ${m.page} of ${m.total}${lastPanel ? ' · ' + lastPanel : ''}`);
      if (m.done) break;
    }
    setProc(3, 72, 'Applying rules');
    const slow = setTimeout(() => P.step === 3 && setProc(4, 85, 'Writing comment sheet and marking pages…'), 900);
    S.data = await api('review', {});
    clearTimeout(slow);
    S.doneIn = (Date.now() - t0) / 1000;
    setProc(5, 100, `Done in ${S.doneIn.toFixed(1)} s`);
  } catch (e) { toast('Could not process: ' + esc(e.message), 7000); S.view = 'home'; return render(); }
  await load();
  S.stamp = Date.now(); S.pdf = null; S.tab = 'comments'; S.page = null;
  setTimeout(() => { S.view = 'ws'; render(); toast(`Review drafted in ${S.doneIn?.toFixed(0) ?? '–'} s · ${S.data.review.comments.length} comments`); }, 700);
}

/* ---------------- workspace ---------------- */
function wsView() {
  const r = S.data.review, p = r.project, st = r.stats;
  const tabs = [['comments', 'Comment sheet', S.comments.length], ['panels', 'Panels', st.panels], ['drawings', 'Marked-up drawings', st.markedPages], ['rules', 'Rules', r.rules.filter(x => x.active !== false).length], ['accuracy', 'vs. engineer', null]];
  const revise = /Revise|Reject/.test(S.decision);
  return `
  <div class="ws-head">
    <div style="min-width:0">
      <div class="label">${esc(p.submittalNo)} · Rev ${p.revision} · Electrical</div>
      <h1 style="margin-top:4px">${esc(p.title)}</h1>
      <div class="sub">${esc(p.vendor)} · <span class="mute">checked against ${esc(p.specDocument)}</span></div>
    </div>
    <div class="decision">
      <div><div class="label" style="margin-bottom:4px">Action ${r.final ? '' : `<span class="chip accent" style="text-transform:none;letter-spacing:0">suggested: ${esc(r.suggested)}</span>`}</div>
      <select id="decision" class="${revise ? 'revise' : ''}">${DECISIONS.map(d => `<option ${d === S.decision ? 'selected' : ''}>${d}</option>`).join('')}</select></div>
      <div><div class="label" style="margin-bottom:4px">Engineer</div><input id="engineer" placeholder="Name for sign-off" value="${esc(S.engineer)}" style="border:1px solid var(--line2);border-radius:9px;padding:8px 10px;background:var(--panel);width:170px"></div>
      <div style="align-self:flex-end;display:flex;gap:8px">
        <a class="btn" href="${outUrl(r)}" download="${esc(r.pdfName)}">${I.down}${r.final ? 'Download' : 'Draft PDF'}</a>
        <button class="btn primary" data-generate>${I.check}Approve &amp; generate</button>
      </div>
    </div>
  </div>
  <div class="kpis">
    <div class="card kpi"><b>${r.pageCount}</b><span>pages read</span></div>
    <div class="card kpi"><b>${st.panels}</b><span>panels identified</span></div>
    <div class="card kpi"><b>${st.checks}</b><span>checks run</span></div>
    <div class="card kpi fail"><b>${st.fail}</b><span>non-compliances</span></div>
    <div class="card kpi warn"><b>${st.unclear}</b><span>need clarification</span></div>
    <div class="card kpi ok"><b>${S.doneIn ? S.doneIn.toFixed(0) + ' s' : '< 1 min'}</b><span>to draft · manual cycle took 19 days</span></div>
  </div>
  <div class="tabs">${tabs.map(([k, n, c]) => `<button data-tab="${k}" class="${S.tab === k ? 'on' : ''}">${n}${c != null ? ` <span class="count">${c}</span>` : ''}</button>`).join('')}</div>
  <div id="tab">${({ comments: commentsTab, panels: panelsTab, drawings: drawingsTab, rules: rulesTab, accuracy: accuracyTab })[S.tab]()}</div>`;
}

function commentsTab() {
  return `<div class="comments">${S.comments.map((c, i) => {
    const edited = c.orig !== undefined ? c.text !== c.orig : c.manual;
    const pages = [...new Set(c.pages || [])];
    return `<div class="card cmt ${edited ? 'edited' : ''}">
      <div class="no">${i + 1}</div>
      <div>
        <textarea data-cmt="${i}" rows="${Math.max(2, Math.ceil(c.text.length / 110))}">${esc(c.text)}</textarea>
        <div class="meta">
          ${c.status === 'unclear' ? '<span class="chip warn"><span class="dot"></span>Clarification</span>' : c.manual ? '<span class="chip accent">Manual</span>' : '<span class="chip fail"><span class="dot"></span>Non-compliance</span>'}
          ${c.clause ? `<span class="chip">${esc(c.clause)}</span>` : ''}
          ${c.panels?.length ? `<span class="chip" title="${esc(c.panels.join(', '))}">${c.panels.length} panel${c.panels.length > 1 ? 's' : ''}</span>` : ''}
          ${edited ? '<span class="chip accent">Edited</span>' : ''}
          <span class="acts">
            ${pages.length ? `<button class="btn ghost sm" data-view-page="${pages[0]}">${I.eye}View on drawing</button>` : ''}
            ${edited && c.orig !== undefined ? `<button class="btn ghost sm" data-revert="${i}" title="Restore the drafted text">${I.undo}</button>` : ''}
            <button class="btn ghost sm" data-del="${i}" title="Remove comment">${I.trash}</button>
          </span>
        </div>
      </div></div>`;
  }).join('')}</div>
  <div class="comments-foot">
    <button class="btn" data-add>${I.plus}Add engineer's comment</button>
    <span class="mute" style="font-size:12.5px">Drafted by the assistant — edit freely. Nothing is issued until you approve.</span>
  </div>`;
}

function resultFor(panel, ids) { return S.data.review.results.find(x => x.panel === panel && ids.includes(x.rule)); }
function panelsTab() {
  const r = S.data.review, f = S.filter;
  const kinds = ['All', ...new Set(r.panels.map(p => p.kind))];
  let rows = r.panels.filter(p => (f.kind === 'All' || p.kind === f.kind) && (!f.q || p.name.toLowerCase().includes(f.q.toLowerCase())));
  if (f.issues) rows = rows.filter(p => r.results.some(x => x.panel === p.name && x.status !== 'pass'));
  const cell = (p, ids) => {
    const x = resultFor(p.name, ids);
    if (!x) return '<td><span class="cell na">—</span></td>';
    return `<td><span class="cell ${x.status}" title="${esc(x.rule + (x.evidence?.page ? ' · page ' + x.evidence.page : ''))}">${x.status === 'pass' ? I.check : ''}${esc(x.shown || x.actual)}</span></td>`;
  };
  return `<div class="filters">
      <div class="seg">${kinds.map(k => `<button data-kind="${k}" class="${f.kind === k ? 'on' : ''}">${k}${k !== 'All' ? ' · ' + r.panels.filter(p => p.kind === k).length : ''}</button>`).join('')}</div>
      <label class="chip" style="cursor:pointer;padding:5px 10px"><input type="checkbox" data-issues ${f.issues ? 'checked' : ''}> Only with issues</label>
      <input type="search" placeholder="Search panel…" data-q value="${esc(f.q)}">
      <span class="mute" style="margin-left:auto;font-size:12.5px">${rows.length} panels · click a row for details</span>
    </div>
    <div class="card tbl-wrap"><table class="grid"><thead><tr><th>Panel</th><th>Pages</th>${COLNAMES.map(c => `<th>${c}</th>`).join('')}</tr></thead><tbody>
    ${rows.map(p => `<tr class="click" data-panel="${esc(p.name)}"><td><b>${esc(p.name)}</b></td><td class="mute mono">${p.first}–${p.last}</td>${COLS.map(c => cell(p, c)).join('')}</tr>`).join('')}
    </tbody></table></div>`;
}

function panelDrawer(name) {
  const r = S.data.review, p = r.panels.find(x => x.name === name);
  const res = r.results.filter(x => x.panel === name);
  const rule = id => r.rules.find(x => x.id === id);
  const el = document.createElement('div');
  el.innerHTML = `<div class="drawer-bg" data-close></div><aside class="drawer">
    <div style="display:flex;justify-content:space-between;align-items:start"><div><div class="label">${esc(p.kind)} · pages ${p.first}–${p.last}</div><h2 style="margin-top:4px">${esc(p.name)}</h2></div><button class="btn ghost sm" data-close>✕</button></div>
    <div style="margin:14px 0 6px" class="label">Checks</div>
    ${res.map(x => `<div class="row"><div><b>${esc(rule(x.rule)?.label)}</b><div class="mute" style="font-size:12px">${esc(rule(x.rule)?.clause.section === '—' ? rule(x.rule)?.clause.path : 'Sec. ' + rule(x.rule)?.clause.section + ' › ' + rule(x.rule)?.clause.path)}</div></div><div class="v"><span class="chip ${x.status}">${esc(x.shown || x.actual)}</span>${x.evidence?.page ? `<div><a style="font-size:12px;cursor:pointer;color:var(--accent)" data-view-page="${x.evidence.page}">page ${x.evidence.page} →</a></div>` : ''}</div></div>`).join('')}
    <div style="margin:18px 0 6px" class="label">Pages in this panel</div>
    ${p.pages.map(g => `<div class="row"><span>Page ${g.page}</span><span class="v"><span class="chip">${esc(g.type)}</span> <a style="font-size:12px;cursor:pointer;color:var(--accent);margin-left:6px" data-view-page="${g.page}">open</a></span></div>`).join('')}
  </aside>`;
  el.id = 'drawer';
  document.body.appendChild(el);
}

// Generated pages at the front of the issued PDF (transmittal, comment sheet, client comments).
const issuedPages = () => (S.data.review.issued || [{ key: 'sheet', title: 'Comment sheet', page: 1 }]).map(g => ({ ...g, id: 'g' + g.page }));

function drawingsTab() {
  const r = S.data.review;
  const pageToPanel = {};
  r.panels.forEach(p => p.pages.forEach(g => (pageToPanel[g.page] = p.name)));
  const marked = Object.keys(r.marks).map(Number).sort((a, b) => a - b);
  if (S.page == null) S.page = marked[0] ?? 1;
  const list = [`<div class="label grp">Issued pages</div>`,
    ...issuedPages().map(g => `<button data-page="${g.id}" class="${S.page === g.id ? 'on' : ''}"><b>${esc(g.title)}</b><small>${g.key === 'sheet' ? `${S.comments.length} comments · ${esc(S.decision)}` : g.key === 'transmittal2' ? esc(S.decision) : g.key === 'client' ? 'Linked submittal' : 'Form F12.5A'}</small></button>`),
    `<button data-page="1" class="${S.page === 1 ? 'on' : ''}"><b>Page 1 · Cover</b><small>Review stamp</small></button>`,
    `<div class="label grp">Marked pages · ${marked.length}</div>`,
    ...marked.map(pg => `<button data-page="${pg}" class="${S.page === pg ? 'on' : ''}"><b>Page ${pg}</b> <span class="mute" style="font-size:12px">${esc(pageToPanel[pg] || '')}</span><small>${r.marks[pg].map(m => `<span style="color:${m.fail ? 'var(--fail)' : 'var(--warn)'}">●</span> ${esc(m.rule)}`).join(' · ')}</small></button>`)];
  return `<div class="viewer">
    <div class="card pagelist">${list.join('')}</div>
    <div class="card canvas-wrap">
      <div class="canvas-bar">
        <button class="btn sm" data-step="-1">${I.left}</button><button class="btn sm" data-step="1">${I.right}</button>
        <b id="pgtitle" class="grow"></b>
        <button class="btn sm" data-zoom="-0.25">−</button><span class="mono" id="zoomv">${Math.round(S.zoom * 100)}%</span><button class="btn sm" data-zoom="0.25">+</button>
      </div>
      <div class="canvas-box" id="cbox"><div class="loading">Loading drawing…</div></div>
    </div></div>`;
}

async function drawPage() {
  const r = S.data.review;
  const outPage = typeof S.page === 'string' ? +S.page.slice(1) || 1 : S.page + r.sheetPages;
  const want = S.page;
  // the whole issued PDF, fetched in ranges: only the pages viewed are downloaded
  if (S.pdfStamp !== S.stamp || !S.pdf) {
    S.pdf = pdfjs.getDocument({ url: outUrl(r), disableAutoFetch: true, disableStream: true, rangeChunkSize: 262144, standardFontDataUrl: STD_FONTS }).promise;
    S.pdfStamp = S.stamp;
  }
  const doc = await S.pdf;
  if (want !== S.page) return; // user moved on while loading
  const page = await doc.getPage(Math.min(outPage, doc.numPages));
  const vp = page.getViewport({ scale: S.zoom * (window.devicePixelRatio || 1) });
  const cv = document.createElement('canvas');
  cv.width = vp.width; cv.height = vp.height; cv.style.width = vp.width / (window.devicePixelRatio || 1) + 'px';
  await page.render({ canvasContext: cv.getContext('2d'), viewport: vp, intent: 'print' }).promise; // 'print' avoids requestAnimationFrame (stalls in background tabs)
  const box = $('#cbox'); if (!box) return;
  box.innerHTML = ''; box.appendChild(cv);
  $('#pgtitle').textContent = typeof S.page === 'string' ? (issuedPages().find(g => g.id === S.page)?.title || 'Issued page') : `Submittal page ${S.page} of ${r.pageCount}`;
}

function rulesTab() {
  const r = S.data.review;
  return `<div class="card">${r.rules.map(x => {
    const exp = typeof x.expected === 'number';
    return `<div class="rule ${x.active === false ? 'off' : ''}">
      <div class="id">${x.id}</div>
      <div>
        <b>${esc(x.label)}</b> <span class="mute">· ${x.appliesTo.map(a => a === '*' ? 'all panels' : a).join(', ')}</span>
        <blockquote>${x.clause.section === '—' ? esc(x.clause.path) : `Section ${esc(x.clause.section)} › ${esc(x.clause.path)}${x.clause.specPage ? ` <span class="mute">(PART F p.${x.clause.specPage})</span>` : ''}`} — “${esc(x.clause.text)}”</blockquote>
        ${x.note ? `<div class="note">⚠ ${esc(x.note)}</div>` : ''}
      </div>
      <div class="ctl">
        ${exp ? `<label class="mute" style="font-size:12.5px">${x.operator === 'gte' ? 'Min. IP' : 'Min. ' + (x.unit || '')} <input type="number" step="${x.attribute === 'ip' ? 1 : 0.5}" data-exp="${x.id}" value="${x.expected}"></label>` : typeof x.expected === 'object' ? `<span class="chip">Form ${x.expected.form} · Type ${x.expected.type}</span>` : ''}
        <label class="switch" title="Active"><input type="checkbox" data-act="${x.id}" ${x.active !== false ? 'checked' : ''}><span></span></label>
      </div></div>`;
  }).join('')}</div>
  <div class="comments-foot"><span class="mute" style="font-size:12.5px">Rules are stored as data — the engineer can add or tune them without a programmer. Changes re-run the check instantly (no re-reading).</span><button class="btn primary" data-apply-rules>Apply &amp; re-check</button></div>`;
}

function accuracyTab() {
  const r = S.data.review;
  const rows = r.engineer.map(e => ({ ...e, sys: S.comments.find(c => c.rule === e.rule) || r.draftComments.find(c => c.rule === e.rule) }));
  const hit = rows.filter(x => x.sys).length;
  const extra = r.draftComments.filter(c => !r.engineer.some(e => e.rule === c.rule));
  return `<div class="card score"><div class="ring" style="--p:${Math.round(100 * hit / rows.length)}"><span>${hit}/${rows.length}</span></div>
    <div><b style="font-size:16px">The assistant found ${hit} of the ${rows.length} points the consultant raised by hand</b>
    <div class="mute">Reference: the consultant's scanned review of this submittal (18 Nov 2025, 19 days after receipt). ${extra.length ? `Plus ${extra.length} additional finding${extra.length > 1 ? 's' : ''} not in the manual review.` : ''}</div></div></div>
  <div class="card acc" style="margin-top:14px">
    <div class="h label">Consultant's manual review</div><div class="h label">Assistant</div>
    ${rows.map(x => `<div><b>${esc(x.no)}.</b> ${esc(x.text)}</div><div>${x.sys ? `<span class="chip ok">${I.check} Found${x.rule === 'P1' ? ' · from submittal register' : ''}</span> <span class="mute" style="font-size:13px">${esc(x.sys.text)}</span>` : '<span class="chip fail">Missed</span>'}</div>`).join('')}
    ${extra.map(c => `<div class="mute">— not raised</div><div><span class="chip accent">Extra finding</span> <span style="font-size:13px">${esc(c.text)}</span></div>`).join('')}
  </div>`;
}

/* ---------------- events ---------------- */
document.addEventListener('click', async e => {
  const t = e.target.closest('[data-go],[data-tab],[data-run-sample],[data-view-page],[data-page],[data-step],[data-zoom],[data-del],[data-revert],[data-add],[data-kind],[data-panel],[data-close],[data-generate],[data-apply-rules],[data-open-rules]');
  if (!t) return;
  const d = t.dataset;
  if (d.go) { if (!S.data.review) return; S.view = 'ws'; render(); }
  else if (d.runSample !== undefined) { e.preventDefault(); runReview(null); }
  else if (d.openRules !== undefined) { if (!S.data.review) return toast('Run a review first to see rules in action'); S.view = 'ws'; S.tab = 'rules'; render(); }
  else if (d.tab) { S.tab = d.tab; render(); }
  else if (d.viewPage) { $('#drawer')?.remove(); S.page = +d.viewPage; S.tab = 'drawings'; render(); }
  else if (d.page) { S.page = d.page.startsWith('g') ? d.page : +d.page; $('#view').innerHTML = wsView(); drawPage(); }
  else if (d.step) {
    const pages = [...issuedPages().map(g => g.id), 1, ...Object.keys(S.data.review.marks).map(Number).sort((a, b) => a - b)];
    const i = Math.max(0, pages.indexOf(S.page)); S.page = pages[Math.min(pages.length - 1, Math.max(0, i + +d.step))];
    $('#view').innerHTML = wsView(); drawPage();
  }
  else if (d.zoom) { S.zoom = Math.min(3, Math.max(0.5, S.zoom + +d.zoom)); $('#zoomv').textContent = Math.round(S.zoom * 100) + '%'; drawPage(); }
  else if (d.del) { S.comments.splice(+d.del, 1); render(); }
  else if (d.revert) { const c = S.comments[+d.revert]; c.text = c.orig; render(); }
  else if (d.add) { S.comments.push({ text: '', manual: true, status: 'fail', clause: 'Engineer', panels: [], pages: [] }); render(); setTimeout(() => [...document.querySelectorAll('[data-cmt]')].pop()?.focus(), 30); }
  else if (d.kind) { S.filter.kind = d.kind; render(); }
  else if (d.panel) panelDrawer(d.panel);
  else if (d.close !== undefined) $('#drawer')?.remove();
  else if (d.applyRules !== undefined) {
    const edits = S.data.review.rules.map(x => ({ id: x.id, active: $(`[data-act="${x.id}"]`).checked, expected: $(`[data-exp="${x.id}"]`)?.value }));
    t.disabled = true; t.textContent = 'Re-checking…';
    try { S.data = await api('rules', edits); } catch (err) { toast('Error: ' + esc(err.message), 6000); }
    await load(); S.stamp = Date.now(); S.pdf = null; S.page = null;
    render(); toast(`Re-checked · ${S.data.review.stats.fail} non-compliances · ${S.comments.length} comments`);
  }
  else if (d.generate !== undefined) {
    const comments = S.comments.filter(c => c.text.trim()).map(({ orig, ...c }) => c);
    t.disabled = true; t.innerHTML = 'Generating…';
    try { await api('generate', { comments, decision: S.decision, engineer: S.engineer }); } catch (err) { toast('Error: ' + esc(err.message), 6000); }
    await load(); S.stamp = Date.now(); S.pdf = null;
    render();
    const r = S.data.review;
    toast(`PDF issued · ${r.comments.length} comments · ${esc(r.decision)} — <a href="${outUrl(r)}" download="${esc(r.pdfName)}">Download</a> · <a href="${window.APP.back}#send">Email to client →</a>`, 12000);
  }
});
document.addEventListener('input', e => {
  const t = e.target;
  if (t.dataset.cmt !== undefined) {
    S.comments[+t.dataset.cmt].text = t.value;
    const card = t.closest('.cmt'); const c = S.comments[+t.dataset.cmt];
    card.classList.toggle('edited', c.orig !== undefined ? c.text !== c.orig : true);
  }
  if (t.id === 'engineer') S.engineer = t.value;
  if (t.dataset.q !== undefined) { S.filter.q = t.value; const pos = t.selectionStart; $('#tab').innerHTML = panelsTab(); const n = $('[data-q]'); n.focus(); n.setSelectionRange(pos, pos); }
});
document.addEventListener('change', e => {
  const t = e.target;
  if (t.id === 'hide') {
    S.hide = t.checked; render();
    if (!S.data.review) { api('settings', { hide: S.hide }).then(load).then(() => S.view === 'home' && ($('#view').innerHTML = homeView())); return; }
    toast(S.hide ? 'Hiding client details on the drawings…' : 'Showing client details on the drawings…', 30000);
    api('settings', { hide: S.hide }).catch(err => toast('Error: ' + esc(err.message), 6000)).then(async () => {
      await load(); S.stamp = Date.now(); // names come back from the server already masked (or not)
      if (S.view === 'ws') { $('#view').innerHTML = wsView(); if (S.tab === 'drawings') drawPage(); }
      crumbs();
      if (S.view === 'home') $('#view').innerHTML = homeView();
      toast(S.hide ? 'Client details hidden — drawings redacted' : 'Client details visible');
    });
  }
  if (t.id === 'decision') { S.decision = t.value; t.className = /Revise|Reject/.test(t.value) ? 'revise' : ''; }
  if (t.dataset.issues !== undefined) { S.filter.issues = t.checked; render(); }
  if (t.id === 'file' && t.files[0]) runReview(t.files[0]);
  if (t.dataset.act) t.closest('.rule').classList.toggle('off', !t.checked);
});
document.addEventListener('dragover', e => { const d = e.target.closest?.('#drop'); if (d) { e.preventDefault(); d.classList.add('over'); } });
document.addEventListener('dragleave', e => e.target.closest?.('#drop')?.classList.remove('over'));
document.addEventListener('drop', e => {
  const d = e.target.closest?.('#drop'); if (!d) return; e.preventDefault(); d.classList.remove('over');
  const f = e.dataTransfer.files[0];
  if (f && f.type === 'application/pdf') runReview(f); else toast('Please drop a PDF file');
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') $('#drawer')?.remove(); });

await load();
if (S.data.review) { const [v, tab] = location.hash.slice(1).split('/'); S.view = 'ws'; S.tab = (v === 'ws' && tab) || 'comments'; }
render();
