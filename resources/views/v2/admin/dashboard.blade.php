@extends('v2.layouts.admin', ['title' => 'Dashboard'])
@section('actions')<a class="btn" href="{{ route('v2.admin.submissions.export') }}"><x-v2.icon name="down"/>Export CSV</a><a class="btn primary" href="{{ route('v2.admin.submissions', ['status' => 'review']) }}">Review queue</a>@endsection
@section('content')
@php $max = max(1, max(array_column($weeks, 'count'))); $dmax = max(1, $decisions->max() ?? 1); @endphp
<div class="kpis">
  <div class="card kpi"><div class="lbl">Submissions</div><b>{{ $kpi['total'] }}</b><small>all time</small></div>
  <div class="card kpi"><div class="lbl">Waiting for review</div><b style="color:{{ $kpi['waiting'] ? 'var(--warn)' : 'inherit' }}">{{ $kpi['waiting'] }}</b><small>received · analysing · in review</small></div>
  <div class="card kpi"><div class="lbl">Issued this month</div><b>{{ $kpi['issuedMonth'] }}</b><small>{{ now()->format('F') }}</small></div>
  <div class="card kpi"><div class="lbl">Average turnaround</div><b>{{ $kpi['turnaround'] === null ? '–' : ($kpi['turnaround'] < 48 ? round($kpi['turnaround'], 1) . ' h' : round($kpi['turnaround'] / 24, 1) . ' d') }}</b><small>submission → issued</small></div>
  <div class="card kpi"><div class="lbl">Unread messages</div><b>{{ $kpi['unread'] }}</b><small><a href="{{ route('v2.admin.messages') }}">open inbox</a></small></div>
</div>

<div class="grid g21">
  <div class="card pad">
    <h2>Submissions per week</h2>
    <div class="bars" style="--n: {{ count($weeks) }}" role="img" aria-label="Submissions per week for the last {{ count($weeks) }} weeks">
      @foreach ($weeks as $i => $w)<div class="b {{ $loop->last ? 'last' : '' }}" title="Week of {{ $w['label'] }}: {{ $w['count'] }}"><span class="v">{{ $w['count'] }}</span><i style="height: {{ round(100 * $w['count'] / $max) }}%"></i></div>@endforeach
    </div>
    <div class="bars-x" style="--n: {{ count($weeks) }}">@foreach ($weeks as $w)<span>{{ $w['label'] }}</span>@endforeach</div>
    <table class="sr"><caption>Submissions per week</caption><tr><th>Week of</th><th>Submissions</th></tr>@foreach ($weeks as $w)<tr><td>{{ $w['label'] }}</td><td>{{ $w['count'] }}</td></tr>@endforeach</table>
  </div>
  <div class="card pad">
    <h2>Decisions issued</h2>
    @forelse ($decisions as $d => $n)
      <div class="hbar"><span>{{ $d }}</span><span class="track"><i style="width: {{ round(100 * $n / $dmax) }}%"></i></span><span class="n">{{ $n }}</span></div>
    @empty
      <p class="mute">No reviews issued yet.</p>
    @endforelse
  </div>
</div>

<div class="grid g21" style="margin-top:16px">
  <div class="card">
    <div class="pad" style="padding-bottom:6px;display:flex;justify-content:space-between"><h2>Review queue</h2><a href="{{ route('v2.admin.submissions') }}">All submissions →</a></div>
    @if ($queue->isEmpty())<div class="empty">Nothing waiting. New submittals from the website appear here.</div>@else
    <div class="tbl"><table class="t"><tr><th>Code</th><th>Project</th><th>Client</th><th>Status</th><th class="num">Waiting</th></tr>
      @foreach ($queue as $s)<tr class="click" onclick="location.href='{{ route('v2.admin.submissions.show', $s) }}'"><td class="mono">{{ $s->code }}</td><td>{{ \Illuminate\Support\Str::limit($s->project_name, 40) }}</td><td>{{ $s->client_company ?: $s->client_name }}</td><td>@include('v2.admin.partials.status')</td><td class="num">{{ $s->created_at->diffForHumans(null, true) }}</td></tr>@endforeach
    </table></div>@endif
  </div>
  <div class="card pad">
    <h2>Activity</h2>
    <ul class="feed">
      @forelse ($activity as $a)<li><span>{{ str_replace(['.', '_'], ' ', $a->action) }}@if ($a->submission) · <a href="{{ route('v2.admin.submissions.show', $a->submission) }}" class="mono">{{ $a->submission->code }}</a>@endif @if ($a->detail)<span class="mute"> — {{ \Illuminate\Support\Str::limit($a->detail, 60) }}</span>@endif</span><span class="when">{{ $a->created_at->diffForHumans(null, true) }}</span></li>
      @empty<li class="mute">No activity yet.</li>@endforelse
    </ul>
  </div>
</div>

<div class="card" style="margin-top:16px">
  <div class="pad" style="padding-bottom:6px"><h2>Latest submissions</h2></div>
  @if ($recent->isEmpty())<div class="empty">No submissions yet — try the form at <a href="{{ route('v2.submit') }}" target="_blank">{{ route('v2.submit') }}</a>.</div>@else
  <div class="tbl"><table class="t"><tr><th>Received</th><th>Code</th><th>Project</th><th>Client</th><th class="num">Pages</th><th>Decision</th><th>Status</th></tr>
    @foreach ($recent as $s)<tr class="click" onclick="location.href='{{ route('v2.admin.submissions.show', $s) }}'"><td>{{ $s->created_at->format('d M, H:i') }}</td><td class="mono">{{ $s->code }}</td><td>{{ \Illuminate\Support\Str::limit($s->project_name, 38) }}</td><td>{{ $s->client_name }}</td><td class="num">{{ $s->page_count ?? '–' }}</td><td>{{ $s->decision ?? '–' }}</td><td>@include('v2.admin.partials.status')</td></tr>@endforeach
  </table></div>@endif
</div>
@endsection
