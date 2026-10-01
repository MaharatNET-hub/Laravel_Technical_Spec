@extends('v2.layouts.admin', ['title' => $s->project_name])
@section('crumb')<div class="mute" style="font-size:12.5px"><a href="{{ route('v2.admin.submissions') }}">Submissions</a> › <span class="mono">{{ $s->code }}</span></div>@endsection
@section('actions')
  @if ($s->hasOriginal())<a class="btn primary" href="{{ route('v2.admin.submissions.workspace', $s) }}"><x-v2.icon name="eye"/>{{ $review ? 'Open analysis' : 'Run analysis' }}</a>@endif
  @if ($s->outputPath())<a class="btn" href="{{ route('v2.admin.submissions.issued', $s) }}"><x-v2.icon name="down"/>{{ ($review['final'] ?? false) ? 'Issued PDF' : 'Draft PDF' }}</a>@endif
@endsection
@section('content')
<div class="grid g21">
  <div class="grid">
    <div class="card pad">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px"><h2 style="margin:0">Review</h2>@include('v2.admin.partials.status')</div>
      @if ($review)
        <div class="kpis" style="grid-template-columns:repeat(4,1fr);margin-bottom:12px">
          <div class="card kpi"><div class="lbl">Pages</div><b>{{ $review['pageCount'] }}</b></div>
          <div class="card kpi"><div class="lbl">Panels</div><b>{{ $review['stats']['panels'] }}</b></div>
          <div class="card kpi"><div class="lbl">Comments</div><b>{{ count($review['comments']) }}</b></div>
          <div class="card kpi"><div class="lbl">Non-compliances</div><b style="color:var(--fail)">{{ $review['stats']['fail'] }}</b></div>
        </div>
        <dl class="kv">
          <dt>{{ ($review['final'] ?? false) ? 'Action' : 'Suggested action' }}</dt><dd><b>{{ $review['decision'] }}</b></dd>
          <dt>Engineer</dt><dd>{{ $review['engineerName'] ?: '–' }}</dd>
          <dt>Client details</dt><dd>{{ $review['hidden'] ? 'Hidden in the PDF (client disclosure on)' : 'Visible' }}</dd>
          <dt>Issued file</dt><dd class="mono">{{ $review['pdfName'] }}</dd>
        </dl>
        <h3 style="margin-top:16px">Comments</h3>
        <ol style="margin:0;padding-inline-start:20px">@foreach ($review['comments'] as $c)<li style="margin-bottom:6px">{{ $c['text'] }}</li>@endforeach</ol>
      @elseif (! $s->hasOriginal())
        <p class="mute">The original PDF is not on the server{{ $s->status === 'uploading' ? ' — the upload was not completed.' : ' (it may have been removed by a host restart).' }}</p>
      @else
        <p class="mute">Not analysed yet. <a href="{{ route('v2.admin.submissions.workspace', $s) }}">Run the analysis</a> — it reads every page and drafts the comment sheet.</p>
      @endif
    </div>

    <div class="card pad">
      <h2>Send to the client</h2>
      @if (($review['final'] ?? false) && $s->outputPath())
        <form class="form" method="post" action="{{ route('v2.admin.submissions.email', $s) }}">
          @csrf
          <div class="row2"><label class="f">To<input class="inp" type="email" name="to" value="{{ $s->client_email }}"></label><label class="f">File<input class="inp" value="{{ basename($s->outputPath()) }} · {{ number_format(filesize($s->outputPath()) / 1048576, 1) }} MB" disabled></label></div>
          <label class="f">Message <small>(optional — added to the email)</small><textarea class="inp" name="note" placeholder="Please address the comments and resubmit as Rev.1."></textarea></label>
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><button class="btn primary"><x-v2.icon name="send"/>Email the review</button>
          <span class="mute" style="font-size:12.5px">PDF attached up to {{ config('v2.mail_attach_mb') }} MB, otherwise a link to the tracking page. @if ($s->emailed_at)Last sent {{ $s->emailed_at->diffForHumans() }}.@endif</span></div>
        </form>
      @else
        <p class="mute" style="margin:0">Available once the review is approved: open the analysis, check the comments and press <b>Approve &amp; generate</b>.</p>
      @endif
    </div>
  </div>

  <div class="grid" style="align-content:start">
    <div class="card pad">
      <h2>Submittal</h2>
      <dl class="kv">
        <dt>Code</dt><dd class="mono">{{ $s->code }}</dd>
        <dt>Received</dt><dd>{{ $s->created_at->format('d M Y, H:i') }}</dd>
        <dt>Project</dt><dd>{{ $s->project_name }}</dd>
        <dt>Title</dt><dd>{{ $s->title ?: '–' }}</dd>
        <dt>Submittal no.</dt><dd>{{ $s->submittal_no ?: '–' }}</dd>
        <dt>Discipline</dt><dd>{{ $s->discipline }}</dd>
        <dt>File</dt><dd>@if ($s->hasOriginal())<a href="{{ route('v2.admin.submissions.original', $s) }}">{{ $s->file_name }}</a>@else{{ $s->file_name ?: '–' }}@endif <span class="mute">· {{ $s->sizeLabel() }}{{ $s->page_count ? ' · ' . $s->page_count . ' pages' : '' }}</span></dd>
      </dl>
      @if ($s->notes)<h3 style="margin-top:14px">Notes from the client</h3><p style="white-space:pre-line;margin:0">{{ $s->notes }}</p>@endif
    </div>
    <div class="card pad">
      <h2>Client</h2>
      <dl class="kv">
        <dt>Name</dt><dd>{{ $s->client_name }}</dd>
        <dt>Company</dt><dd>{{ $s->client_company ?: '–' }}</dd>
        <dt>Email</dt><dd><a href="mailto:{{ $s->client_email }}">{{ $s->client_email }}</a></dd>
        <dt>Phone</dt><dd>{{ $s->client_phone ?: '–' }}</dd>
        <dt>Language</dt><dd>{{ $s->locale === 'ar' ? 'Arabic' : 'English' }}</dd>
        <dt>Tracking page</dt><dd><a href="{{ route('v2.track.show', $s->code) }}" target="_blank">open ↗</a></dd>
      </dl>
    </div>
    <div class="card pad">
      <h2>Status</h2>
      <form method="post" action="{{ route('v2.admin.submissions.update', $s) }}" style="display:flex;gap:8px">@csrf @method('patch')
        <select class="inp" name="status">@foreach (\App\Models\V2\Submission::STATUSES as $st)<option value="{{ $st }}" @selected($s->status === $st)>{{ ucfirst($st) }}</option>@endforeach</select>
        <button class="btn">Save</button>
      </form>
    </div>
    <div class="card pad">
      <h2>History</h2>
      <ul class="feed">@forelse ($activity as $a)<li><span>{{ str_replace(['.', '_'], ' ', $a->action) }}@if ($a->detail)<span class="mute"> — {{ $a->detail }}</span>@endif @if ($a->user)<span class="mute"> · {{ $a->user->name }}</span>@endif</span><span class="when">{{ $a->created_at->format('d M H:i') }}</span></li>@empty<li class="mute">No history.</li>@endforelse</ul>
    </div>
    <form method="post" action="{{ route('v2.admin.submissions.destroy', $s) }}" onsubmit="return confirm('Delete this submission and all its files? This cannot be undone.')">@csrf @method('delete')<button class="btn danger"><x-v2.icon name="trash"/>Delete submission</button></form>
  </div>
</div>
@endsection
