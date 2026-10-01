@extends('v2.layouts.admin', ['title' => 'Messages'])
@section('content')
<div class="card">
  @if ($items->isEmpty())<div class="empty">No messages yet. The contact form on the website sends them here.</div>@else
  <div class="tbl"><table class="t"><tr><th>Received</th><th>From</th><th>Subject</th><th></th></tr>
  @foreach ($items as $m)
    <tr class="click" onclick="location.href='{{ route('v2.admin.messages.show', $m) }}'" style="{{ $m->read_at ? '' : 'font-weight:600' }}">
      <td style="white-space:nowrap">{{ $m->created_at->format('d M Y, H:i') }}</td>
      <td>{{ $m->name }}<div class="mute" style="font-size:12.5px;font-weight:400">{{ $m->email }}</div></td>
      <td>{{ $m->subject ?: \Illuminate\Support\Str::limit($m->body, 70) }}</td>
      <td>@unless ($m->read_at)<span class="chip accent">New</span>@endunless</td>
    </tr>
  @endforeach
  </table></div>{{ $items->links() }}@endif
</div>
@endsection
