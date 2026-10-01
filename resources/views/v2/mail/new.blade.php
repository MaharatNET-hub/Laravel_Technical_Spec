@extends('v2.mail.layout', ['s' => (object) ['locale' => 'en']])
@section('body')
<p><b>New submittal through the website</b></p>
<table cellpadding="4" style="font-size:14px">
<tr><td style="color:#64748b">Code</td><td><b>{{ $s->code }}</b></td></tr>
<tr><td style="color:#64748b">Project</td><td>{{ $s->project_name }}</td></tr>
<tr><td style="color:#64748b">Title</td><td>{{ $s->title }}</td></tr>
<tr><td style="color:#64748b">From</td><td>{{ $s->client_name }}{{ $s->client_company ? ' · ' . $s->client_company : '' }} &lt;{{ $s->client_email }}&gt;</td></tr>
<tr><td style="color:#64748b">File</td><td>{{ $s->file_name }} ({{ $s->sizeLabel() }})</td></tr>
</table>
@if ($s->notes)<p style="background:#f8fafc;border-radius:8px;padding:10px 12px">{{ $s->notes }}</p>@endif
<p><a href="{{ route('v2.admin.submissions.show', $s) }}" style="display:inline-block;background:#1f4fbf;color:#fff;text-decoration:none;padding:11px 18px;border-radius:9px;font-weight:600">Open in the dashboard</a></p>
@endsection
