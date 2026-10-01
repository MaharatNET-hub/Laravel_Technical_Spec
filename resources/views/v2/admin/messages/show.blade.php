@extends('v2.layouts.admin', ['title' => $m->subject ?: 'Message'])
@section('crumb')<div class="mute" style="font-size:12.5px"><a href="{{ route('v2.admin.messages') }}">Messages</a></div>@endsection
@section('actions')<a class="btn primary" href="mailto:{{ $m->email }}?subject={{ rawurlencode('Re: ' . ($m->subject ?: 'Your message')) }}"><x-v2.icon name="mail"/>Reply by email</a>
<form method="post" action="{{ route('v2.admin.messages.destroy', $m) }}" onsubmit="return confirm('Delete this message?')">@csrf @method('delete')<button class="btn danger"><x-v2.icon name="trash"/>Delete</button></form>@endsection
@section('content')
<div class="grid g21">
  <div class="card pad"><p style="white-space:pre-line;margin:0;font-size:15px">{{ $m->body }}</p></div>
  <div class="card pad"><dl class="kv"><dt>From</dt><dd>{{ $m->name }}</dd><dt>Email</dt><dd><a href="mailto:{{ $m->email }}">{{ $m->email }}</a></dd><dt>Phone</dt><dd>{{ $m->phone ?: '–' }}</dd><dt>Received</dt><dd>{{ $m->created_at->format('d M Y, H:i') }}</dd></dl></div>
</div>
@endsection
