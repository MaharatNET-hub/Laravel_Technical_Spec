<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Mail\V2\ReviewIssued;
use App\Models\V2\Activity;
use App\Models\V2\Submission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class SubmissionController extends Controller
{
    private function filtered(Request $r)
    {
        $q = Submission::query()->latest();
        if ($r->filled('status')) {
            $q->where('status', $r->query('status'));
        }
        if ($r->filled('q')) {
            $term = '%' . $r->query('q') . '%';
            $q->where(fn ($w) => $w->where('code', 'like', $term)->orWhere('project_name', 'like', $term)->orWhere('client_name', 'like', $term)
                ->orWhere('client_company', 'like', $term)->orWhere('client_email', 'like', $term)->orWhere('title', 'like', $term)->orWhere('submittal_no', 'like', $term));
        }

        return $q;
    }

    public function index(Request $r)
    {
        $counts = Submission::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return view('v2.admin.submissions.index', ['items' => $this->filtered($r)->paginate(20)->withQueryString(), 'counts' => $counts]);
    }

    /** Spreadsheet of the (filtered) list — opens in Excel. */
    public function export(Request $r)
    {
        $rows = $this->filtered($r)->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Arabic correctly
            fputcsv($out, ['Code', 'Status', 'Received', 'Client', 'Company', 'Email', 'Phone', 'Project', 'Submittal no.', 'Title', 'Discipline', 'Pages', 'Comments', 'Decision', 'Engineer', 'Issued', 'Emailed']);
            foreach ($rows as $s) {
                fputcsv($out, [$s->code, $s->status, $s->created_at?->format('Y-m-d H:i'), $s->client_name, $s->client_company, $s->client_email, $s->client_phone, $s->project_name,
                    $s->submittal_no, $s->title, $s->discipline, $s->page_count, $s->comment_count, $s->decision, $s->engineer, $s->issued_at?->format('Y-m-d H:i'), $s->emailed_at?->format('Y-m-d H:i')]);
            }
            fclose($out);
        }, 'submissions-' . date('Y-m-d') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function show(Submission $submission)
    {
        return view('v2.admin.submissions.show', ['s' => $submission, 'review' => $submission->review(), 'activity' => $submission->activities()->with('user')->get()]);
    }

    public function update(Request $r, Submission $submission)
    {
        $data = $r->validate(['status' => 'required|in:' . implode(',', Submission::STATUSES)]);
        if ($data['status'] !== $submission->status) {
            $submission->update($data);
            Activity::log($submission, 'status.' . $data['status'], 'changed by hand');
        }

        return back()->with('ok', 'Status updated.');
    }

    public function destroy(Submission $submission)
    {
        $code = $submission->code;
        $submission->delete();

        return redirect()->route('v2.admin.submissions')->with('ok', "Submission $code and its files were deleted.");
    }

    public function original(Submission $submission)
    {
        abort_unless($submission->hasOriginal(), 404);

        return response()->download($submission->originalPath(), $submission->file_name ?: 'submittal.pdf');
    }

    public function issued(Submission $submission)
    {
        abort_unless($f = $submission->outputPath(), 404);

        return response()->download($f, basename($f));
    }

    public function workspace(Submission $submission)
    {
        return view('v2.admin.submissions.workspace', ['s' => $submission]);
    }

    /** Email the reviewed file to the client. */
    public function email(Request $r, Submission $submission)
    {
        $data = $r->validate(['note' => 'nullable|string|max:3000', 'to' => 'nullable|email']);
        if (! $submission->outputPath()) {
            return back()->with('bad', 'Generate the reviewed PDF first.');
        }
        if (! ($submission->review()['final'] ?? false)) {
            return back()->with('bad', 'The review is still a draft: open the analysis and press "Approve & generate" first.');
        }
        $to = ($data['to'] ?? null) ?: $submission->client_email;
        try {
            $mail = new ReviewIssued($submission, (string) ($data['note'] ?? ''));
            Mail::to($to)->send($mail);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('bad', 'Email failed: ' . $e->getMessage());
        }
        $submission->update(['emailed_at' => now(), 'status' => 'issued', 'issued_at' => $submission->issued_at ?? now()]);
        Activity::log($submission, 'review.emailed', "to $to" . ($mail->attached ? ' · PDF attached' : ' · download link'));
        $logOnly = in_array(config('mail.default'), ['log', 'array'], true);

        return back()->with($logOnly ? 'bad' : 'ok', $logOnly
            ? "No mail server is configured (MAIL_MAILER=" . config('mail.default') . "): the email to $to was written to storage/logs/mail.log instead of being sent. Set the SMTP settings in .env."
            : "Review emailed to $to.");
    }
}
