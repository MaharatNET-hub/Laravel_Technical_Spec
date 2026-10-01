@extends('v2.layouts.site', ['title' => __('v2.submit.thanks')])
@section('content')
<section class="block">
  <div class="wrap" style="max-width:720px;text-align:center">
    <div style="width:64px;height:64px;border-radius:50%;background:#dcfce7;color:var(--ok);display:grid;place-items:center;margin:0 auto 18px"><x-v2.icon name="check" class="i" style="width:32px;height:32px"/></div>
    <h1 style="font-size:clamp(26px,4vw,38px)">{{ __('v2.submit.thanks') }}</h1>
    <p class="mute" style="font-size:17px">{{ $s->title ?: $s->project_name }} · {{ $s->file_name }}</p>
    <p style="margin:26px 0 8px;font-weight:600">{{ __('v2.submit.code') }}</p>
    <div class="code">{{ $s->code }}</div>
    <p class="mute" style="margin-top:16px">{{ __('v2.submit.keep') }}</p>
    <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:24px">
      <a class="btn primary" href="{{ route('v2.track.show', $s->code) }}">{{ __('v2.submit.track_it') }}</a>
      <a class="btn line" href="{{ route('v2.submit') }}">{{ __('v2.submit.another') }}</a>
    </div>
  </div>
</section>
@endsection
