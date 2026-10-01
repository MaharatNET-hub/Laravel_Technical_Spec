@php use App\V2\Site; $c = Site::contact(); $nav = ['home' => 'v2.home', 'about' => 'v2.about', 'services' => 'v2.services', 'projects' => 'v2.projects', 'contact' => 'v2.contact']; @endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ Site::ar() ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ isset($title) ? $title . ' · ' : '' }}{{ Site::name() }}</title>
<meta name="description" content="{{ Site::t(Site::company(), 'tagline') }}">
<meta name="csrf-token" content="{{ csrf_token() }}">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('lib/v2/site.css') }}?v={{ filemtime(public_path('lib/v2/site.css')) }}">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%2312306b'/%3E%3Ccircle cx='25' cy='25' r='6' fill='%23f2a516'/%3E%3C/svg%3E">
</head>
<body>
<header class="top">
  <div class="wrap">
    <a class="logo" href="{{ route('v2.home') }}"><span class="mark">{{ Site::initial() }}</span><span>{{ Site::name() }}</span></a>
    <button class="burger" type="button" aria-label="Menu" onclick="document.getElementById('nav').classList.toggle('open')"><x-v2.icon name="menu"/></button>
    <nav class="nav" id="nav">
      @foreach ($nav as $k => $r)
        <a href="{{ route($r) }}" class="{{ request()->routeIs($r) || ($k === 'projects' && request()->routeIs('v2.project')) ? 'on' : '' }}">{{ __("v2.nav.$k") }}</a>
      @endforeach
      <a href="{{ route('v2.track') }}" class="{{ request()->routeIs('v2.track*') ? 'on' : '' }}">{{ __('v2.nav.track') }}</a>
    </nav>
    <div class="tools">
      <a class="lang" href="{{ Site::switchUrl() }}" hreflang="{{ Site::ar() ? 'en' : 'ar' }}">{{ __('v2.lang_switch') }}</a>
      <a class="btn primary" href="{{ route('v2.submit') }}"><x-v2.icon name="upload"/>{{ __('v2.nav.submit_short') }}</a>
    </div>
  </div>
</header>

<main>@yield('content')</main>

<footer class="foot">
  <div class="wrap">
    <div class="cols">
      <div>
        <a class="logo" href="{{ route('v2.home') }}"><span class="mark">{{ Site::initial() }}</span><span>{{ Site::name() }}</span></a>
        <p style="margin-top:14px;max-width:42ch">{{ Site::t(Site::company(), 'tagline') }}</p>
      </div>
      <div>
        <h4>{{ __('v2.footer.quick') }}</h4>
        @foreach ($nav as $k => $r)<a href="{{ route($r) }}">{{ __("v2.nav.$k") }}</a>@endforeach
        <a href="{{ route('v2.submit') }}">{{ __('v2.nav.submit') }}</a>
      </div>
      <div>
        <h4>{{ __('v2.footer.reach') }}</h4>
        @if (!empty($c['email']))<a href="mailto:{{ $c['email'] }}" dir="ltr">{{ $c['email'] }}</a>@endif
        @if (!empty($c['phone']))<a href="tel:{{ preg_replace('/[^+\d]/', '', $c['phone']) }}" dir="ltr">{{ $c['phone'] }}</a>@endif
        <span style="display:block;padding:4px 0">{{ Site::t($c, 'address') }}</span>
      </div>
    </div>
    <div class="bottom"><span>© {{ date('Y') }} {{ Site::name() }}. {{ __('v2.footer.rights') }}</span><a href="{{ route('v2.admin.dashboard') }}" style="display:inline;color:#64748b">{{ __('v2.nav.admin') }}</a></div>
  </div>
</footer>
@stack('scripts')
</body>
</html>
