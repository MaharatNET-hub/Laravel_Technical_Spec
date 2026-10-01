@php
  use App\V2\Site;
  $unread = \App\Models\V2\Message::whereNull('read_at')->count();
  $waiting = \App\Models\V2\Submission::whereIn('status', ['received', 'analysing', 'review'])->count();
  $items = [
    ['v2.admin.dashboard', 'dash', 'Dashboard', null, 'v2.admin.dashboard'],
    ['v2.admin.submissions', 'clipboard', 'Submissions', $waiting, 'v2.admin.submissions*'],
    ['v2.admin.messages', 'inbox', 'Messages', $unread, 'v2.admin.messages*'],
  ];
  $site = [
    ['v2.admin.company', 'building', 'Company profile', 'v2.admin.company'],
    ['v2.admin.services.index', 'star', 'Services', 'v2.admin.services.*'],
    ['v2.admin.projects.index', 'file', 'Projects', 'v2.admin.projects.*'],
    ['v2.admin.settings', 'cog', 'Settings', 'v2.admin.settings'],
  ];
@endphp
<!doctype html>
<html lang="en" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $title ?? 'Dashboard' }} · Admin · {{ Site::name() }}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Sans+Arabic:wght@400;600&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('lib/v2/admin.css') }}?v={{ filemtime(public_path('lib/v2/admin.css')) }}">
</head>
<body>
<div class="shell">
  <aside class="sidebar" id="sb">
    <a class="brand" href="{{ route('v2.admin.dashboard') }}"><span class="mark">{{ Site::initial() }}</span><span>{{ \Illuminate\Support\Str::limit(Site::t(Site::company(), 'name') ?: 'Admin', 22) }}<small>Admin</small></span></a>
    @foreach ($items as [$r, $ic, $label, $badge, $pat])
      <a class="nav {{ request()->routeIs($pat) ? 'on' : '' }}" href="{{ route($r) }}"><x-v2.icon :name="$ic"/>{{ $label }}@if ($badge)<span class="badge">{{ $badge }}</span>@endif</a>
    @endforeach
    <div class="grp">Website</div>
    @foreach ($site as [$r, $ic, $label, $pat])
      <a class="nav {{ request()->routeIs($pat) ? 'on' : '' }}" href="{{ route($r) }}"><x-v2.icon :name="$ic"/>{{ $label }}</a>
    @endforeach
    <div class="bottom">
      <a class="nav" href="{{ route('v2.home') }}" target="_blank"><x-v2.icon name="globe"/>View website</a>
      <a class="nav" href="{{ url('/') }}" target="_blank"><x-v2.icon name="eye"/>v1 demo</a>
      <form method="post" action="{{ route('v2.admin.logout') }}">@csrf<button><x-v2.icon name="out"/>Log out <span class="mute" style="font-size:12px;margin-inline-start:auto">{{ \Illuminate\Support\Str::limit(auth()->user()->email, 18) }}</span></button></form>
    </div>
  </aside>
  <div class="main">
    <header class="topbar">
      <div style="display:flex;align-items:center;gap:12px;min-width:0">
        <button class="btn burger" type="button" onclick="document.getElementById('sb').classList.toggle('open')" aria-label="Menu"><x-v2.icon name="menu"/></button>
        <div style="min-width:0">@yield('crumb')<h1>{{ $title ?? 'Dashboard' }}</h1></div>
      </div>
      <div class="acts">@yield('actions')</div>
    </header>
    <div class="content">
      @if (session('ok'))<div class="alert ok">{{ session('ok') }}</div>@endif
      @if (session('bad'))<div class="alert bad">{{ session('bad') }}</div>@endif
      @if ($errors->any())<div class="alert bad">{{ $errors->first() }}</div>@endif
      @yield('content')
    </div>
  </div>
</div>
@stack('scripts')
</body>
</html>
