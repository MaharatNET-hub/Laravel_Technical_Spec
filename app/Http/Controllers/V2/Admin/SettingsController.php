<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\V2\Setting;
use App\V2\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class SettingsController extends Controller
{
    public function company()
    {
        return view('v2.admin.company', ['co' => Site::company(), 'c' => Site::contact(), 'stats' => array_pad(Site::stats(), 4, ['value' => '', 'label_en' => '', 'label_ar' => ''])]);
    }

    public function saveCompany(Request $r)
    {
        $d = $r->validate([
            'co.name_en' => 'required|string|max:160', 'co.name_ar' => 'required|string|max:160', 'co.tagline_en' => 'nullable|string|max:300', 'co.tagline_ar' => 'nullable|string|max:300',
            'co.about_en' => 'nullable|string|max:8000', 'co.about_ar' => 'nullable|string|max:8000', 'co.mission_en' => 'nullable|string|max:600', 'co.mission_ar' => 'nullable|string|max:600',
            'co.founded' => 'nullable|integer|min:1900|max:2100',
            'c.email' => 'nullable|email|max:160', 'c.phone' => 'nullable|string|max:40', 'c.whatsapp' => 'nullable|string|max:40',
            'c.address_en' => 'nullable|string|max:300', 'c.address_ar' => 'nullable|string|max:300', 'c.hours_en' => 'nullable|string|max:120', 'c.hours_ar' => 'nullable|string|max:120',
            'c.linkedin' => 'nullable|url|max:300',
            'stats' => 'array|max:6', 'stats.*.value' => 'nullable|string|max:20', 'stats.*.label_en' => 'nullable|string|max:60', 'stats.*.label_ar' => 'nullable|string|max:60',
        ]);
        Setting::put('company', array_map(fn ($v) => $v ?? '', $d['co']));
        Setting::put('contact', array_map(fn ($v) => $v ?? '', $d['c'] ?? []));
        Setting::put('stats', array_values(array_filter($d['stats'] ?? [], fn ($s) => trim((string) ($s['value'] ?? '')) !== '')));

        return back()->with('ok', 'Company profile saved — it is live on the website.');
    }

    public function settings()
    {
        return view('v2.admin.settings', ['rv' => Site::review(), 'mailer' => config('mail.default'), 'from' => config('mail.from.address')]);
    }

    public function saveSettings(Request $r)
    {
        $d = $r->validate(['notify_email' => 'nullable|email|max:160']);
        Setting::put('review', ['hide_default' => $r->boolean('hide_default'), 'auto_analyse' => $r->boolean('auto_analyse'), 'notify_email' => $d['notify_email'] ?? '']);

        return back()->with('ok', 'Settings saved.');
    }

    public function password(Request $r)
    {
        $r->validate(['current' => 'required|current_password', 'password' => 'required|string|min:10|confirmed']);
        $r->user()->update(['password' => Hash::make($r->input('password'))]);

        return back()->with('ok', 'Password changed.' . (config('v2.admin_password') ? ' Note: V2_ADMIN_PASSWORD is set in the environment and will reset it on the next start — update or remove it there too.' : ''));
    }

    public function testMail(Request $r)
    {
        try {
            Mail::raw('Test email from ' . Site::name() . ' — mail settings work.', fn ($m) => $m->to($r->user()->email)->subject('Test email'));
        } catch (\Throwable $e) {
            return back()->with('bad', 'Sending failed: ' . $e->getMessage());
        }
        $logOnly = in_array(config('mail.default'), ['log', 'array'], true);

        return back()->with($logOnly ? 'bad' : 'ok', $logOnly ? 'MAIL_MAILER is "' . config('mail.default') . '": the test email was written to storage/logs/mail.log, not sent.' : 'Test email sent to ' . $r->user()->email . '.');
    }
}
