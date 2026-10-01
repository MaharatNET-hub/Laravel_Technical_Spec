@extends('v2.layouts.site', ['title' => __('v2.submit.title')])
@section('content')
<section class="page-head"><div class="wrap"><h1>{{ __('v2.submit.title') }}</h1><p>{{ __('v2.submit.sub') }}</p></div></section>
<section class="block">
  <div class="wrap layout2">
    <div class="card">
      <form class="form" id="sf" novalidate>
        <div class="hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
        <div class="legend">{{ __('v2.submit.you') }}</div>
        <div class="row2">
          <label class="f">{{ __('v2.contact.name') }} *<input class="inp" name="client_name" required maxlength="120"></label>
          <label class="f">{{ __('v2.submit.company') }}<input class="inp" name="client_company" maxlength="160"></label>
        </div>
        <div class="row2">
          <label class="f">{{ __('v2.contact.email') }} *<input class="inp" type="email" name="client_email" required maxlength="160" dir="ltr"></label>
          <label class="f">{{ __('v2.contact.phone') }}<input class="inp" name="client_phone" maxlength="40" dir="ltr"></label>
        </div>
        <div class="legend">{{ __('v2.submit.the_submittal') }}</div>
        <div class="row2">
          <label class="f">{{ __('v2.submit.project') }} *<input class="inp" name="project_name" required maxlength="200"></label>
          <label class="f">{{ __('v2.submit.number') }}<input class="inp" name="submittal_no" maxlength="80" dir="ltr"></label>
        </div>
        <div class="row2">
          <label class="f">{{ __('v2.submit.title_field') }}<input class="inp" name="title" maxlength="255"></label>
          <label class="f">{{ __('v2.submit.discipline') }}<select class="inp" name="discipline">@foreach (__('v2.submit.disciplines') as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></label>
        </div>
        <label class="f">{{ __('v2.submit.notes') }}<textarea class="inp" name="notes" maxlength="3000" style="min-height:90px"></textarea></label>
        <div>
          <div class="f" style="font-weight:600;font-size:14.5px;color:var(--ink2);margin-bottom:6px">{{ __('v2.submit.file') }} *</div>
          <label class="drop" id="drop">
            <input type="file" id="file" accept="application/pdf,.pdf" hidden>
            <span class="ic"><x-v2.icon name="upload"/></span>
            <b>{{ __('v2.submit.drop') }}</b>
            <span class="mute" style="font-size:14px">{{ __('v2.submit.file_hint', ['mb' => $maxMb]) }}</span>
          </label>
          <div class="picked" id="picked" hidden><span class="fi">PDF</span><div style="min-width:0"><b id="pname" style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></b><span class="mute" id="psize" style="font-size:13px"></span></div><button type="button" class="btn line" id="clear" style="margin-inline-start:auto;padding:8px 10px" aria-label="Remove"><x-v2.icon name="x"/></button></div>
        </div>
        <div id="msg" class="alert bad" hidden></div>
        <div id="prog" hidden><div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:6px"><b id="ptext"></b><span id="ppct" class="mute"></span></div><div class="progress"><i id="pbar"></i></div></div>
        <div><button class="btn primary" id="go"><x-v2.icon name="send"/>{{ __('v2.submit.send') }}</button></div>
      </form>
    </div>
    <aside class="side">
      <div class="card">
        <ul class="ticks" style="margin:0">@foreach (__('v2.home.review_points') as $pt)<li><x-v2.icon name="check"/><span>{{ $pt }}</span></li>@endforeach</ul>
      </div>
      <div class="card" style="display:flex;gap:12px;align-items:flex-start"><x-v2.icon name="shield" class="i" style="color:var(--ok);margin-top:3px"/><span class="mute" style="font-size:15px">{{ __('v2.submit.privacy') }}</span></div>
    </aside>
  </div>
</section>
@push('scripts')
@php $texts = ['uploading' => __('v2.submit.uploading'), 'analysing' => __('v2.submit.analysing'), 'done' => __('v2.submit.done'), 'pdf' => __('v2.submit.err_pdf'), 'size' => __('v2.submit.err_size', ['mb' => $maxMb]), 'required' => __('v2.errors.required')]; @endphp
<script>
(() => {
  const T = {!! json_encode($texts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
  const MAX = {{ (int) $maxMb }} * 1048576, CHUNK = {{ (int) $chunk }};
  const U = { create: @json(route('v2.submit.create')), chunk: @json(url('v2/submit/__C__/chunk')), step: @json(url('v2/submit/__C__/step')), thanks: @json(url('v2/submit/__C__/thanks')) };
  const csrf = document.querySelector('meta[name=csrf-token]').content;
  const $ = id => document.getElementById(id);
  let file = null;
  const showErr = m => { $('msg').textContent = m; $('msg').hidden = !m; };
  const pick = f => {
    if (!f) return;
    if (f.type !== 'application/pdf' && !/\.pdf$/i.test(f.name)) return showErr(T.pdf);
    if (f.size > MAX) return showErr(T.size);
    file = f; showErr('');
    $('pname').textContent = f.name; $('psize').textContent = (f.size / 1048576).toFixed(1) + ' MB';
    $('picked').hidden = false; $('drop').hidden = true;
  };
  $('file').addEventListener('change', e => pick(e.target.files[0]));
  $('clear').addEventListener('click', () => { file = null; $('file').value = ''; $('picked').hidden = true; $('drop').hidden = false; });
  const drop = $('drop');
  drop.addEventListener('dragover', e => { e.preventDefault(); drop.classList.add('over'); });
  drop.addEventListener('dragleave', () => drop.classList.remove('over'));
  drop.addEventListener('drop', e => { e.preventDefault(); drop.classList.remove('over'); pick(e.dataTransfer.files[0]); });
  const prog = (label, pct) => { $('prog').hidden = false; $('ptext').textContent = label; $('ppct').textContent = Math.round(pct) + '%'; $('pbar').style.width = pct + '%'; };
  const post = async (url, body, json = true) => {
    const res = await fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', ...(json ? { 'Content-Type': 'application/json' } : {}) }, body: json ? JSON.stringify(body) : body });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) { const first = data.errors ? Object.values(data.errors)[0][0] : null; throw new Error(first || data.error || data.message || ('Error ' + res.status)); }
    return data;
  };
  $('sf').addEventListener('submit', async e => {
    e.preventDefault(); showErr('');
    const form = e.target;
    for (const el of form.querySelectorAll('[required]')) if (!el.value.trim()) { el.focus(); return showErr(T.required); }
    if (!file) return showErr(T.pdf);
    const btn = $('go'); btn.disabled = true;
    try {
      const fields = Object.fromEntries(new FormData(form)); fields.file_name = file.name; fields.file_size = file.size;
      const { code } = await post(U.create, fields);
      const total = Math.max(1, Math.ceil(file.size / CHUNK)); let res;
      for (let i = 0; i < total; i++) {
        prog(T.uploading, 60 * i / total);
        res = await post(U.chunk.replace('__C__', code) + `?index=${i}&total=${total}`, file.slice(i * CHUNK, (i + 1) * CHUNK), false);
      }
      prog(T.analysing, 62);
      if (res.analyse) {
        for (let n = 0; n < 400; n++) {
          const m = await post(U.step.replace('__C__', code), {}).catch(() => ({ done: true }));
          if (m.total) prog(`${T.analysing} · ${m.page || 0} / ${m.total}`, 62 + 36 * (m.page || 0) / m.total);
          if (m.done) break;
        }
      }
      prog(T.done, 100);
      location.href = U.thanks.replace('__C__', code);
    } catch (err) { showErr(err.message); btn.disabled = false; }
  });
})();
</script>
@endpush
@endsection
