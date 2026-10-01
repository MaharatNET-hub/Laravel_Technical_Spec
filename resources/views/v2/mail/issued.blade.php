@extends('v2.mail.layout')
@section('body')
@if ($s->locale === 'ar')
<p>مرحباً {{ $s->client_name }}،</p>
<p>صدرت مراجعة تقديمك <b>{{ $s->title ?: $s->project_name }}</b>.</p>
<p>القرار: <b style="color:#b91c1c">{{ $s->decision }}</b> · عدد الملاحظات: <b>{{ $s->comment_count }}</b></p>
@else
<p>Hello {{ $s->client_name }},</p>
<p>The review of your submittal <b>{{ $s->title ?: $s->project_name }}</b> has been issued.</p>
<p>Action: <b style="color:#b91c1c">{{ $s->decision }}</b> · Comments: <b>{{ $s->comment_count }}</b></p>
@endif
@if ($note)<p style="background:#f8fafc;border-radius:8px;padding:12px 14px;white-space:pre-line">{{ $note }}</p>@endif
<p>{{ $attached ? ($s->locale === 'ar' ? 'الملف المراجَع مرفق بهذه الرسالة.' : 'The reviewed file is attached to this email.') : ($s->locale === 'ar' ? 'يمكنك تحميل الملف المراجَع من صفحة التتبّع:' : 'Download the reviewed file from the tracking page:') }}</p>
<p><a href="{{ route('v2.track.show', $s->code) }}" style="display:inline-block;background:#1f4fbf;color:#fff;text-decoration:none;padding:11px 18px;border-radius:9px;font-weight:600">{{ $s->locale === 'ar' ? 'فتح صفحة التتبّع' : 'Open the tracking page' }}</a></p>
<p style="color:#64748b;font-size:13px">{{ $s->locale === 'ar' ? 'رمز التتبّع' : 'Tracking code' }}: <span dir="ltr">{{ $s->code }}</span></p>
@endsection
