@php use App\V2\Site; $rtl = ($s->locale ?? 'en') === 'ar'; @endphp
<!doctype html>
<html lang="{{ $rtl ? 'ar' : 'en' }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<body style="margin:0;background:#f1f5f9;font-family:Segoe UI,Tahoma,Arial,sans-serif;color:#0f172a">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:28px 12px"><tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e2e8f0">
<tr><td style="background:#12306b;padding:22px 28px;color:#ffffff;font-size:18px;font-weight:700">{{ Site::name() }}</td></tr>
<tr><td style="padding:28px;font-size:15px;line-height:1.65;text-align:{{ $rtl ? 'right' : 'left' }}">@yield('body')</td></tr>
<tr><td style="padding:18px 28px;background:#f8fafc;color:#64748b;font-size:12.5px;text-align:{{ $rtl ? 'right' : 'left' }}">{{ Site::t(Site::contact(), 'address') }}@if (!empty(Site::contact()['phone'])) · {{ Site::contact()['phone'] }}@endif</td></tr>
</table></td></tr></table>
</body></html>
