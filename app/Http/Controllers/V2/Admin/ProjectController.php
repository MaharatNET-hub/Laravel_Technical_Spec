<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\V2\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProjectController extends Controller
{
    public function index()
    {
        return view('v2.admin.projects.index', ['items' => Project::orderBy('sort')->get()]);
    }

    public function create()
    {
        return view('v2.admin.projects.form', ['p' => new Project(['accent' => '#1f4fbf', 'active' => true, 'year' => (int) date('Y')])]);
    }

    public function store(Request $r)
    {
        $p = new Project;
        $this->save($r, $p);

        return redirect()->route('v2.admin.projects.index')->with('ok', 'Project added.');
    }

    public function edit(Project $project)
    {
        return view('v2.admin.projects.form', ['p' => $project]);
    }

    public function update(Request $r, Project $project)
    {
        $this->save($r, $project);

        return redirect()->route('v2.admin.projects.index')->with('ok', 'Project saved.');
    }

    public function destroy(Project $project)
    {
        if ($project->image) {
            Storage::disk('public')->delete($project->image);
        }
        $project->delete();

        return back()->with('ok', 'Project deleted.');
    }

    private function save(Request $r, Project $p): void
    {
        $data = $r->validate([
            'title_en' => 'required|string|max:200', 'title_ar' => 'required|string|max:200', 'slug' => 'nullable|alpha_dash|max:120|unique:v2_projects,slug,' . ($p->id ?? 'NULL'),
            'category' => 'nullable|string|max:60', 'location_en' => 'nullable|string|max:120', 'location_ar' => 'nullable|string|max:120', 'year' => 'nullable|integer|min:1950|max:2100',
            'summary_en' => 'nullable|string|max:600', 'summary_ar' => 'nullable|string|max:600', 'body_en' => 'nullable|string|max:20000', 'body_ar' => 'nullable|string|max:20000',
            'accent' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'], 'sort' => 'nullable|integer|min:0|max:9999',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
        ]);
        $data['slug'] = ($data['slug'] ?? null) ?: ($p->slug ?: (Str::slug($data['title_en']) ?: Str::lower(Str::random(8))));
        if (Project::where('slug', $data['slug'])->whereKeyNot($p->id)->exists()) {
            $data['slug'] .= '-' . Str::lower(Str::random(4)); // a title already used by another project
        }
        $data['featured'] = $r->boolean('featured');
        $data['active'] = $r->boolean('active');
        $data['sort'] ??= 0;
        $data['accent'] ??= '#1f4fbf';
        unset($data['image']);
        if ($r->hasFile('image')) {
            if ($p->image) {
                Storage::disk('public')->delete($p->image);
            }
            $data['image'] = $r->file('image')->store('v2/projects', 'public');
        } elseif ($r->boolean('remove_image') && $p->image) {
            Storage::disk('public')->delete($p->image);
            $data['image'] = null;
        }
        $p->fill($data)->save();
    }
}
