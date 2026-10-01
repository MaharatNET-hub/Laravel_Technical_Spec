@extends('v2.layouts.site', ['title' => __('v2.about.title')])
@php use App\V2\Site; $co = Site::company(); @endphp
@section('content')
<section class="page-head"><div class="wrap"><div class="crumb">{{ Site::name() }}</div><h1>{{ __('v2.about.title') }}</h1><p>{{ Site::t($co, 'tagline') }}</p></div></section>
<section class="block">
  <div class="wrap layout2">
    <div class="prose">
      @foreach (preg_split('/\n\s*\n/', Site::t($co, 'about')) as $para)<p>{{ $para }}</p>@endforeach
      @if (Site::t($co, 'mission'))<h2 style="margin-top:34px">{{ __('v2.about.mission') }}</h2><div class="mission">{{ Site::t($co, 'mission') }}</div>@endif
    </div>
    <aside class="side">
      <div class="card">
        <h3>{{ __('v2.about.numbers') }}</h3>
        @if (!empty($co['founded']))<p class="mute">{{ __('v2.about.founded', ['year' => $co['founded']]) }}</p>@endif
        <dl class="kv">@foreach ($stats as $s)<dt>{{ Site::t($s, 'label') }}</dt><dd>{{ $s['value'] }}</dd>@endforeach</dl>
      </div>
      <a class="btn primary" href="{{ route('v2.contact') }}">{{ __('v2.nav.contact') }}</a>
    </aside>
  </div>
</section>
<section class="block alt"><div class="wrap"><div class="head"><h2>{{ __('v2.home.services_title') }}</h2><a href="{{ route('v2.services') }}">{{ __('v2.home.all_services') }} →</a></div>
  <div class="grid g3">@foreach ($services->take(3) as $s)<div class="card svc"><div class="ic"><x-v2.icon :name="$s->icon"/></div><h3>{{ $s->t('title') }}</h3><p>{{ $s->t('summary') }}</p></div>@endforeach</div></div></section>
@endsection
