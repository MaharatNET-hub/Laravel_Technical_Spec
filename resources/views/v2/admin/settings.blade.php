@extends('v2.layouts.admin', ['title' => 'Settings'])
@section('content')
<div class="grid g2" style="max-width:1100px">
  <form class="card pad form" method="post" action="{{ route('v2.admin.settings.save') }}">
    @csrf @method('put')
    <h2>Submittal review</h2>
    <label class="check"><input type="checkbox" name="hide_default" value="1" @checked($rv['hide_default'])> Hide client details by default in new reviews <span class="mute" style="font-weight:400">(client disclosure)</span></label>
    <label class="check"><input type="checkbox" name="auto_analyse" value="1" @checked($rv['auto_analyse'])> Analyse automatically right after the client uploads</label>
    <label class="f">Notify this address of new submissions <small>(blank = the company email)</small><input class="inp" type="email" name="notify_email" value="{{ $rv['notify_email'] }}"></label>
    <div><button class="btn primary">Save</button></div>
  </form>
  <div class="card pad form">
    <h2>Email</h2>
    <dl class="kv"><dt>Mailer</dt><dd class="mono">{{ $mailer }}</dd><dt>From</dt><dd class="mono">{{ $from }}</dd></dl>
    @if (in_array($mailer, ['log', 'array'], true))
      <div class="alert bad" style="margin:0">Emails are not sent yet: they are written to <code>storage/logs/mail.log</code>. Set <code>MAIL_MAILER=smtp</code>, <code>MAIL_HOST</code>, <code>MAIL_PORT</code>, <code>MAIL_USERNAME</code>, <code>MAIL_PASSWORD</code> and <code>MAIL_FROM_ADDRESS</code> in <code>.env</code> (or the host's environment).</div>
    @endif
    <form method="post" action="{{ route('v2.admin.settings.test-mail') }}">@csrf<button class="btn"><x-v2.icon name="send"/>Send a test email to {{ auth()->user()->email }}</button></form>
  </div>
  <form class="card pad form" method="post" action="{{ route('v2.admin.account.password') }}">
    @csrf @method('put')
    <h2>Your password</h2>
    <label class="f">Current password<input class="inp" type="password" name="current" required autocomplete="current-password"></label>
    <div class="row2"><label class="f">New password <small>(10+ characters)</small><input class="inp" type="password" name="password" required minlength="10" autocomplete="new-password"></label>
    <label class="f">Repeat<input class="inp" type="password" name="password_confirmation" required autocomplete="new-password"></label></div>
    <div><button class="btn primary">Change password</button></div>
  </form>
  <div class="card pad">
    <h2>Versions</h2>
    <p class="mute" style="margin:0 0 10px">v1 (the original demo) is still at <a href="{{ url('/') }}" target="_blank">{{ url('/') }}</a> and is not affected by anything here. v2 is the website at <a href="{{ route('v2.home') }}" target="_blank">{{ route('v2.home') }}</a> and this dashboard.</p>
    <dl class="kv"><dt>Submissions folder</dt><dd class="mono" style="font-size:12px">storage/app/v2/submissions</dd><dt>Database</dt><dd class="mono">{{ config('database.default') }}</dd></dl>
  </div>
</div>
@endsection
