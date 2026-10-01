@extends('v2.layouts.site', ['title' => __('v2.track.title')])
@section('content')
<section class="page-head"><div class="wrap"><h1>{{ __('v2.track.title') }}</h1><p>{{ __('v2.track.sub') }}</p></div></section>
<section class="block">
  <div class="wrap" style="max-width:640px">
    <form class="card form" method="get" action="{{ route('v2.track') }}">
      <label class="f">{{ __('v2.track.code') }}<input class="inp" name="code" value="{{ $code }}" placeholder="ABCD-123456" required dir="ltr" style="font-family:JetBrains Mono,monospace;letter-spacing:.05em;text-transform:uppercase"></label>
      @if ($notFound)<div class="alert bad">{{ __('v2.track.not_found') }}</div>@endif
      <div><button class="btn primary"><x-v2.icon name="search"/>{{ __('v2.track.find') }}</button></div>
    </form>
  </div>
</section>
@endsection
