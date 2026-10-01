<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\V2\Submission;
use Illuminate\Http\Request;

/** Clients follow their submittal with the tracking code (the code is the secret: 10 random characters). */
class TrackController extends Controller
{
    public function form(Request $r)
    {
        if ($r->filled('code')) {
            $code = strtoupper(trim((string) $r->query('code')));

            return Submission::where('code', $code)->exists()
                ? redirect()->route('v2.track.show', $code)
                : view('v2.site.track', ['notFound' => true, 'code' => $code]);
        }

        return view('v2.site.track', ['notFound' => false, 'code' => '']);
    }

    public function show(string $code)
    {
        $s = Submission::where('code', strtoupper($code))->firstOrFail();

        return view('v2.site.track-show', ['s' => $s, 'canDownload' => $s->status === 'issued' && $s->outputPath()]);
    }

    public function download(string $code)
    {
        $s = Submission::where('code', strtoupper($code))->firstOrFail();
        abort_unless($s->status === 'issued' && ($f = $s->outputPath()), 404);

        return response()->download($f, basename($f), ['Content-Type' => 'application/pdf']);
    }
}
