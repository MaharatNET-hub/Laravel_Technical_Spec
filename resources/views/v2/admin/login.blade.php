@php use App\V2\Site; @endphp
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>Admin login · {{ Site::name() }}</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('lib/v2/admin.css') }}?v={{ filemtime(public_path('lib/v2/admin.css')) }}"></head>
<body><div class="login"><div class="card">
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:20px"><span style="width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,#12306b,#1f4fbf);color:#fff;display:grid;place-items:center;font-weight:800">{{ Site::initial() }}</span><div><b>{{ Site::name() }}</b><div class="mute" style="font-size:13px">Admin dashboard</div></div></div>
  <form class="form" method="post" action="{{ route('v2.admin.login.post') }}">
    @csrf
    @if ($errors->any())<div class="alert bad" style="margin:0">{{ $errors->first() }}</div>@endif
    <label class="f">Email<input class="inp" type="email" name="email" value="{{ old('email') }}" required autofocus></label>
    <label class="f">Password<input class="inp" type="password" name="password" required></label>
    <label class="check"><input type="checkbox" name="remember" value="1"> Keep me signed in</label>
    <button class="btn primary" style="justify-content:center;padding:11px">Sign in</button>
  </form>
  <p class="mute" style="font-size:12.5px;margin:18px 0 0">First run: the admin account comes from <code>V2_ADMIN_EMAIL</code> / <code>V2_ADMIN_PASSWORD</code>. If no password was set, a random one is in <code>storage/app/v2/ADMIN-PASSWORD.txt</code> and the server log.</p>
  <p style="font-size:13px;margin:12px 0 0"><a href="{{ route('v2.home') }}">← Back to the website</a></p>
</div></div></body></html>
