@extends('v2.layouts.admin', ['title' => 'Submissions'])
@section('actions')<a class="btn" href="{{ route('v2.admin.submissions.export', request()->query()) }}"><x-v2.icon name="down"/>Export CSV</a><a class="btn" href="{{ route('v2.submit') }}" target="_blank"><x-v2.icon name="upload"/>Public form</a>@endsection
@section('content')
<form class="filters" method="get">
  <div class="seg">
    <a href="{{ route('v2.admin.submissions', array_filter(['q' => request('q')])) }}" class="{{ request('status') ? '' : 'on' }}">All<span class="n">{{ $counts->sum() }}</span></a>
    @foreach (\App\Models\V2\Submission::STATUSES as $st)@if ($counts[$st] ?? 0)<a href="{{ route('v2.admin.submissions', array_filter(['status' => $st, 'q' => request('q')])) }}" class="{{ request('status') === $st ? 'on' : '' }}">{{ ucfirst($st) }}<span class="n">{{ $counts[$st] }}</span></a>@endif @endforeach
  </div>
  <input type="hidden" name="status" value="{{ request('status') }}">
  <input class="inp" type="search" name="q" value="{{ request('q') }}" placeholder="Search code, project, client, email…" style="max-width:320px">
  <button class="btn"><x-v2.icon name="search"/>Search</button>
</form>
<div class="card">
  @if ($items->isEmpty())<div class="empty">No submissions match.</div>@else
  <div class="tbl"><table class="t">
    <tr><th>Received</th><th>Code</th><th>Project / title</th><th>Client</th><th class="num">Pages</th><th class="num">Comments</th><th>Decision</th><th>Status</th></tr>
    @foreach ($items as $s)
      <tr class="click" onclick="location.href='{{ route('v2.admin.submissions.show', $s) }}'">
        <td>{{ $s->created_at->format('d M Y') }}<div class="mute" style="font-size:12px">{{ $s->created_at->format('H:i') }}</div></td>
        <td class="mono"><a href="{{ route('v2.admin.submissions.show', $s) }}">{{ $s->code }}</a></td>
        <td><b>{{ \Illuminate\Support\Str::limit($s->project_name, 40) }}</b><div class="mute" style="font-size:12.5px">{{ \Illuminate\Support\Str::limit($s->title ?: $s->file_name, 50) }}</div></td>
        <td>{{ $s->client_name }}<div class="mute" style="font-size:12.5px">{{ $s->client_company }}</div></td>
        <td class="num">{{ $s->page_count ?? '–' }}</td><td class="num">{{ $s->comment_count ?? '–' }}</td>
        <td>{{ $s->decision ?? '–' }}</td><td>@include('v2.admin.partials.status')</td>
      </tr>
    @endforeach
  </table></div>
  {{ $items->links() }}
  @endif
</div>
@endsection
