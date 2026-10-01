<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Mail\V2\NewSubmission;
use App\Mail\V2\SubmissionReceived;
use App\Models\V2\Activity;
use App\Models\V2\Submission;
use App\Pdf\Reader;
use App\V2\Site;
use App\V2\Submissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Public "submit a submittal" flow: create the record, receive the PDF in 1 MB chunks (shared hosts
 * cap request size), then the visitor's browser drives the first analysis in short steps.
 */
class SubmitController extends Controller
{
    public const CHUNK = 1048576;

    public function form()
    {
        return view('v2.site.submit', ['chunk' => self::CHUNK, 'maxMb' => config('v2.max_upload_mb')]);
    }

    public function create(Request $r): JsonResponse
    {
        if ($r->filled('website')) {
            return response()->json(['error' => 'Rejected'], 422);
        }
        $data = $r->validate([
            'client_name' => 'required|string|max:120', 'client_company' => 'nullable|string|max:160', 'client_email' => 'required|email|max:160',
            'client_phone' => 'nullable|string|max:40', 'project_name' => 'required|string|max:200', 'submittal_no' => 'nullable|string|max:80',
            'discipline' => 'required|in:Electrical,Mechanical,Plumbing,Fire,Other', 'title' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:3000',
            'file_name' => 'required|string|max:255', 'file_size' => 'required|integer|min:100',
        ]);
        if ($data['file_size'] > config('v2.max_upload_mb') * 1048576) {
            return response()->json(['error' => __('v2.submit.err_size', ['mb' => config('v2.max_upload_mb')])], 422);
        }
        $data['file_name'] = basename($data['file_name']);
        $data['locale'] = app()->getLocale();
        $s = Submission::create($data + ['status' => 'uploading']);
        $r->session()->push('v2_submissions', $s->id); // only this visitor may upload to / drive it

        return response()->json(['code' => $s->code, 'chunk' => self::CHUNK]);
    }

    private function owned(Request $r, string $code): Submission
    {
        $s = Submission::where('code', $code)->firstOrFail();
        abort_unless(in_array($s->id, (array) $r->session()->get('v2_submissions', []), true), 403);

        return $s;
    }

    public function chunk(Request $r, string $code): JsonResponse
    {
        $s = $this->owned($r, $code);
        abort_unless($s->status === 'uploading', 409);
        $index = (int) $r->query('index', 0);
        $total = max(1, (int) $r->query('total', 1));
        $data = $r->getContent();
        if (strlen($data) > self::CHUNK + 1024) {
            return response()->json(['error' => 'Chunk too large'], 413);
        }
        File::ensureDirectoryExists($s->dir());
        $part = $s->dir() . '/upload.part';
        if ($index === 0) {
            if (! str_starts_with($data, '%PDF')) {
                return response()->json(['error' => __('v2.submit.err_pdf')], 422);
            }
            file_put_contents($part, $data);
        } else {
            file_put_contents($part, $data, FILE_APPEND);
        }
        if (filesize($part) > config('v2.max_upload_mb') * 1048576) {
            @unlink($part);

            return response()->json(['error' => __('v2.submit.err_size', ['mb' => config('v2.max_upload_mb')])], 422);
        }
        if ($index < $total - 1) {
            return response()->json(['received' => $index + 1]);
        }

        // last chunk: check it really is a readable PDF
        try {
            $pages = count((new Reader((string) file_get_contents($part)))->pages());
        } catch (\Throwable $e) {
            @unlink($part);

            return response()->json(['error' => $e->getMessage()], 422);
        }
        rename($part, $s->originalPath());
        $s->update(['status' => 'received', 'file_size' => filesize($s->originalPath()), 'page_count' => $pages]);
        Activity::log($s, 'submission.received', "{$s->file_name} · $pages pages");
        Submissions::prepare($s);
        $this->notify($s);

        return response()->json(['pages' => $pages, 'analyse' => (bool) Site::review()['auto_analyse']]);
    }

    private function notify(Submission $s): void
    {
        try {
            Mail::to($s->client_email)->send(new SubmissionReceived($s));
            $office = Site::review()['notify_email'] ?: (Site::contact()['email'] ?? null);
            if ($office) {
                Mail::to($office)->send(new NewSubmission($s));
            }
        } catch (\Throwable $e) {
            Log::warning('v2 mail failed: ' . $e->getMessage());
        }
    }

    /** The visitor's browser runs the first analysis in short steps (no queue worker on shared hosting). */
    public function step(Request $r, string $code): JsonResponse
    {
        $s = $this->owned($r, $code);
        @set_time_limit(120);
        @ini_set('memory_limit', '512M');
        try {
            if (in_array($s->status, ['received'], true) || ($s->status === 'analysing' && ! is_file($s->dir() . '/job.json'))) {
                Submissions::start($s);
            }
            if (! in_array($s->status, ['analysing'], true)) {
                return response()->json(['done' => true, 'page' => $s->page_count, 'total' => $s->page_count]);
            }

            return response()->json(Submissions::step($s->fresh(), (float) config('demo.step_seconds', 12)));
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Analysis paused — our team will complete it.', 'done' => true], 200);
        }
    }

    public function thanks(string $code)
    {
        return view('v2.site.thanks', ['s' => Submission::where('code', $code)->firstOrFail()]);
    }
}
