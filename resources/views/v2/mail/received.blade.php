@extends('v2.mail.layout')
@section('body')
@if ($s->locale === 'ar')
<p>مرحباً {{ $s->client_name }}،</p>
<p>استلمنا تقديمك <b>{{ $s->title ?: $s->project_name }}</b> ({{ $s->file_name }}). سيقرأ مساعد المراجعة الملف، ثم يراجعه مهندسنا ويرسل لك النتيجة.</p>
<p>رمز التتبّع:</p>
@else
<p>Hello {{ $s->client_name }},</p>
<p>We received your submittal <b>{{ $s->title ?: $s->project_name }}</b> ({{ $s->file_name }}). Our review assistant reads it first, then one of our engineers reviews it and sends you the result.</p>
<p>Your tracking code:</p>
@endif
<p style="font:700 24px/1.2 Consolas,monospace;letter-spacing:2px;color:#12306b;background:#eef2f8;border-radius:10px;padding:12px 16px;display:inline-block" dir="ltr">{{ $s->code }}</p>
<p><a href="{{ route('v2.track.show', $s->code) }}" style="display:inline-block;background:#1f4fbf;color:#fff;text-decoration:none;padding:11px 18px;border-radius:9px;font-weight:600">{{ $s->locale === 'ar' ? 'تتبّع التقديم' : 'Track your submittal' }}</a></p>
@endsection
