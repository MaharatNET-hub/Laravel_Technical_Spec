@extends('v2.layouts.site')
@php use App\V2\Site; $co = Site::company(); @endphp
@section('content')
<section class="hero">
  <div class="wrap">
    <div>
      <span class="eyebrow">{{ __('v2.hero.eyebrow') }}</span>
      <h1 style="margin-top:14px">{{ Site::name() }}</h1>
      <p class="lead">{{ Site::t($co, 'tagline') }}</p>
      <div class="ctas">
        <a class="btn accent" href="{{ route('v2.submit') }}"><x-v2.icon name="upload"/>{{ __('v2.hero.cta') }}</a>
        <a class="btn ghost" href="{{ route('v2.projects') }}">{{ __('v2.hero.cta2') }} <x-v2.icon name="arrow" class="i arrow"/></a>
      </div>
    </div>
    <div class="hero-card" aria-hidden="true">
      <div class="row"><span class="dot"><x-v2.icon name="file"/></span>EMDB-A-1 · Data sheet<span class="tag fail">IP43 &lt; IP54</span></div>
      <div class="row"><span class="dot"><x-v2.icon name="file"/></span>SMDB-B-3 · Form of separation<span class="tag warn">Clarify</span></div>
      <div class="row"><span class="dot"><x-v2.icon name="file"/></span>Aux. wiring 2.5 mm²<span class="tag ok">OK</span></div>
      <div class="row"><span class="dot"><x-v2.icon name="clipboard"/></span>Comment sheet · 4 comments<span class="tag warn">Draft</span></div>
    </div>
  </div>
</section>

@if ($stats)
<section class="block" style="padding-bottom:0">
  <div class="wrap"><div class="stats">@foreach ($stats as $s)<div><b>{{ $s['value'] }}</b><span>{{ Site::t($s, 'label') }}</span></div>@endforeach</div></div>
</section>
@endif

<section class="block">
  <div class="wrap">
    <div class="head"><div><span class="eyebrow">{{ __('v2.nav.services') }}</span><h2 style="margin-top:10px">{{ __('v2.home.services_title') }}</h2><p>{{ __('v2.home.services_sub') }}</p></div><a href="{{ route('v2.services') }}">{{ __('v2.home.all_services') }} →</a></div>
    <div class="grid g3">
      @foreach ($services as $s)
        <div class="card svc"><div class="ic"><x-v2.icon :name="$s->icon"/></div><h3>{{ $s->t('title') }}</h3><p>{{ $s->t('summary') }}</p></div>
      @endforeach
    </div>
  </div>
</section>

<section class="block alt">
  <div class="wrap feature">
    <div>
      <span class="eyebrow">{{ __('v2.nav.submit') }}</span>
      <h2 style="margin-top:10px">{{ __('v2.home.review_title') }}</h2>
      <p class="mute" style="font-size:17px">{{ __('v2.home.review_sub') }}</p>
      <ul class="ticks">@foreach (__('v2.home.review_points') as $pt)<li><x-v2.icon name="check"/><span>{{ $pt }}</span></li>@endforeach</ul>
      <a class="btn primary" href="{{ route('v2.submit') }}"><x-v2.icon name="upload"/>{{ __('v2.hero.cta') }}</a>
    </div>
    <div class="mock" dir="ltr" aria-hidden="true">
      <div class="bar"><i></i><i></i><i></i></div>
      <div class="ln"><span class="no">1</span><b>ESMDB need to be IP54 not 43 — Sec. 262300 › 2.6.B</b><span class="tag fail">Fail</span></div>
      <div class="ln"><span class="no">2</span><b>Vendor to clarify SMDB Form 2 Type 2</b><span class="tag warn">Clarify</span></div>
      <div class="ln"><span class="no">3</span><b>Aux. wiring 1.5 mm² not accepted — min 2.5</b><span class="tag fail">Fail</span></div>
      <div class="ln"><span class="no">4</span><b>Anti-condensation heater + thermostat</b><span class="tag fail">Fail</span></div>
    </div>
  </div>
</section>

<section class="block">
  <div class="wrap">
    <div class="head"><div><span class="eyebrow">{{ __('v2.nav.projects') }}</span><h2 style="margin-top:10px">{{ __('v2.home.projects_title') }}</h2><p>{{ __('v2.home.projects_sub') }}</p></div><a href="{{ route('v2.projects') }}">{{ __('v2.home.all_projects') }} →</a></div>
    <div class="grid g3">@foreach ($projects as $p) @include('v2.partials.project-card') @endforeach</div>
  </div>
</section>

@include('v2.partials.cta')
@endsection
