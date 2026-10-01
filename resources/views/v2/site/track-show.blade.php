@extends('v2.layouts.site', ['title' => __('v2.track.title')])
@php $order = ['received', 'analysing', 'review', 'issued']; $at = array_search($s->status === 'uploading' ? 'received' : ($s->status === 'archived' ? 'issued' : $s->status), $order); @endphp
@section('content')
<section class="page-head"><div class="wrap"><div class="crumb"><a href="{{ route('v2.track') }}">← {{ __('v2.track.title') }}</a></div><h1>{{ $s->title ?: $s->project_name }}</h1><div class="code" style="font-size:20px;padding:8px 14px">{{ $s->code }}</div></div></section>
<section class="block">
  <div class="wrap" style="max-width:860px">
    <div class="card">
      <div class="steps">@foreach ($order as $i => $k)<div class="{{ $i < $at || ($k === 'issued' && $s->status === 'issued') ? 'done' : ($i === $at ? 'now' : '') }}">{{ __("v2.track.steps.$k") }}</div>@endforeach</div>
      <dl class="kv">
        <dt>{{ __('v2.track.status') }}</dt><dd>{{ __("v2.track.statuses.{$s->status}") }}</dd>
        <dt>{{ __('v2.submit.project') }}</dt><dd>{{ $s->project_name }}</dd>
        <dt>{{ __('v2.track.submitted') }}</dt><dd dir="ltr" style="text-align:start">{{ $s->created_at->format('d M Y, H:i') }}</dd>
        @if ($s->page_count)<dt>{{ __('v2.submit.file') }}</dt><dd>{{ $s->file_name }} · {{ $s->page_count }} {{ __('v2.track.pages') }}</dd>@endif
        @if ($s->status === 'issued')<dt>{{ __('v2.track.decision') }}</dt><dd style="color:var(--fail)">{{ $s->decision }}</dd>@endif
      </dl>
      @if ($canDownload)<p style="margin:24px 0 0"><a class="btn primary" href="{{ route('v2.track.download', $s->code) }}"><x-v2.icon name="down"/>{{ __('v2.track.download') }}</a></p>@endif
    </div>
  </div>
</section>
@endsection
