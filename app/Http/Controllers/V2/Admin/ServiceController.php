<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\V2\Service;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public const ICONS = ['bolt', 'fan', 'drop', 'clipboard', 'helmet', 'chart', 'shield', 'building', 'globe', 'cog', 'star', 'file'];

    public function index()
    {
        return view('v2.admin.services.index', ['items' => Service::orderBy('sort')->get()]);
    }

    public function create()
    {
        return view('v2.admin.services.form', ['s' => new Service(['icon' => 'bolt', 'active' => true, 'sort' => Service::max('sort') + 1]), 'icons' => self::ICONS]);
    }

    public function store(Request $r)
    {
        Service::create($this->data($r));

        return redirect()->route('v2.admin.services.index')->with('ok', 'Service added.');
    }

    public function edit(Service $service)
    {
        return view('v2.admin.services.form', ['s' => $service, 'icons' => self::ICONS]);
    }

    public function update(Request $r, Service $service)
    {
        $service->update($this->data($r));

        return redirect()->route('v2.admin.services.index')->with('ok', 'Service saved.');
    }

    public function destroy(Service $service)
    {
        $service->delete();

        return back()->with('ok', 'Service deleted.');
    }

    private function data(Request $r): array
    {
        $d = $r->validate([
            'icon' => 'required|in:' . implode(',', self::ICONS), 'title_en' => 'required|string|max:160', 'title_ar' => 'required|string|max:160',
            'summary_en' => 'nullable|string|max:600', 'summary_ar' => 'nullable|string|max:600', 'sort' => 'nullable|integer|min:0|max:9999',
        ]);
        $d['active'] = $r->boolean('active');
        $d['sort'] ??= 0;

        return $d;
    }
}
