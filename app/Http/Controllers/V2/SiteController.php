<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\V2\Message;
use App\Models\V2\Project;
use App\Models\V2\Service;
use App\V2\Site;
use Illuminate\Http\Request;

class SiteController extends Controller
{
    private function activeServices()
    {
        return Service::where('active', true)->orderBy('sort')->get();
    }

    public function home()
    {
        $projects = Project::where('active', true)->orderByDesc('featured')->orderBy('sort')->take(3)->get();

        return view('v2.site.home', ['services' => $this->activeServices(), 'projects' => $projects, 'stats' => Site::stats()]);
    }

    public function about()
    {
        return view('v2.site.about', ['services' => $this->activeServices(), 'stats' => Site::stats()]);
    }

    public function services()
    {
        return view('v2.site.services', ['services' => $this->activeServices()]);
    }

    public function projects(Request $r)
    {
        $q = Project::where('active', true)->orderBy('sort');
        $category = $r->query('category');
        if ($category) {
            $q->where('category', $category);
        }

        return view('v2.site.projects', [
            'projects' => $q->get(), 'category' => $category,
            'categories' => Project::where('active', true)->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
        ]);
    }

    public function project(Project $project)
    {
        abort_unless($project->active, 404);
        $more = Project::where('active', true)->whereKeyNot($project->id)->orderByDesc('featured')->orderBy('sort')->take(3)->get();

        return view('v2.site.project', compact('project', 'more'));
    }

    public function contact()
    {
        return view('v2.site.contact');
    }

    public function contactSend(Request $r)
    {
        if ($r->filled('website')) { // honeypot: bots fill every field
            return back()->with('sent', true);
        }
        $data = $r->validate([
            'name' => 'required|string|max:120', 'email' => 'required|email|max:160', 'phone' => 'nullable|string|max:40',
            'subject' => 'nullable|string|max:160', 'body' => 'required|string|max:5000',
        ]);
        Message::create($data);

        return redirect()->route('v2.contact')->with('sent', true);
    }

    /** Project images uploaded from the dashboard (served without needing storage:link). */
    public function media(string $path)
    {
        abort_unless(preg_match('#^v2/projects/[A-Za-z0-9._-]+$#', $path), 404);
        $f = storage_path('app/public/' . $path);
        abort_unless(is_file($f), 404);

        return response()->file($f, ['Cache-Control' => 'public, max-age=604800']);
    }
}
