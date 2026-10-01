@extends('v2.layouts.site', ['title' => __('v2.contact.title')])
@php use App\V2\Site; $c = Site::contact(); @endphp
@section('content')
<section class="page-head"><div class="wrap"><h1>{{ __('v2.contact.title') }}</h1><p>{{ __('v2.contact.sub') }}</p></div></section>
<section class="block">
  <div class="wrap layout2">
    <div class="card">
      @if (session('sent'))<div class="alert ok" style="margin-bottom:18px">{{ __('v2.contact.sent') }}</div>@endif
      <form class="form" method="post" action="{{ route('v2.contact.send') }}">
        @csrf
        <div class="hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
        <div class="row2">
          <label class="f">{{ __('v2.contact.name') }}<input class="inp" name="name" value="{{ old('name') }}" required maxlength="120">@error('name')<span class="err">{{ $message }}</span>@enderror</label>
          <label class="f">{{ __('v2.contact.email') }}<input class="inp" type="email" name="email" value="{{ old('email') }}" required maxlength="160" dir="ltr">@error('email')<span class="err">{{ $message }}</span>@enderror</label>
        </div>
        <div class="row2">
          <label class="f">{{ __('v2.contact.phone') }}<input class="inp" name="phone" value="{{ old('phone') }}" maxlength="40" dir="ltr"></label>
          <label class="f">{{ __('v2.contact.subject') }}<input class="inp" name="subject" value="{{ old('subject') }}" maxlength="160"></label>
        </div>
        <label class="f">{{ __('v2.contact.message') }}<textarea class="inp" name="body" required maxlength="5000">{{ old('body') }}</textarea>@error('body')<span class="err">{{ $message }}</span>@enderror</label>
        <div><button class="btn primary"><x-v2.icon name="send"/>{{ __('v2.contact.send') }}</button></div>
      </form>
    </div>
    <aside class="side"><div class="card">
      @if (!empty($c['email']))<div class="info"><span class="ic"><x-v2.icon name="mail"/></span><div><b>{{ __('v2.contact.email') }}</b><a href="mailto:{{ $c['email'] }}" dir="ltr">{{ $c['email'] }}</a></div></div>@endif
      @if (!empty($c['phone']))<div class="info"><span class="ic"><x-v2.icon name="phone"/></span><div><b>{{ __('v2.contact.phone') }}</b><a href="tel:{{ preg_replace('/[^+\d]/', '', $c['phone']) }}" dir="ltr">{{ $c['phone'] }}</a></div></div>@endif
      @if (Site::t($c, 'address'))<div class="info"><span class="ic"><x-v2.icon name="pin"/></span><div><b>{{ __('v2.contact.address') }}</b>{{ Site::t($c, 'address') }}</div></div>@endif
      @if (Site::t($c, 'hours'))<div class="info"><span class="ic"><x-v2.icon name="clock"/></span><div><b>{{ __('v2.contact.hours') }}</b>{{ Site::t($c, 'hours') }}</div></div>@endif
    </div></aside>
  </div>
</section>
@endsection
