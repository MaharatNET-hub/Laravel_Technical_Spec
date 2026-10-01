<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\DemoController;
use App\Models\V2\Activity;
use App\Models\V2\Submission;
use App\Review\Store;
use App\V2\Submissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The v1 analysis tool's API, for one submission. ScopeSubmission has already pointed the engine at
 * the submission's folder; these methods add the submission bookkeeping (status, log).
 */
class WorkspaceController extends DemoController
{
    private function after(Submission $s): void
    {
        $review = Store::read('review.json');
        if ($review) {
            Submissions::sync($s, $review);
        }
    }

    public function state(?Submission $submission = null): JsonResponse
    {
        return parent::state();
    }

    public function start(Submission $submission): JsonResponse
    {
        if (! $submission->hasOriginal()) {
            return response()->json(['error' => 'The original PDF is not on the server (it may have been removed by a host restart).'], 404);
        }
        try {
            $job = Submissions::start($submission);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['total' => $job['total']]);
    }

    public function step(?Submission $submission = null): JsonResponse
    {
        @set_time_limit(120);
        try {
            return response()->json(Submissions::step($submission, (float) config('demo.step_seconds', 12)));
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function review(?Submission $submission = null): JsonResponse
    {
        @set_time_limit(300);
        try {
            if (! is_file(Store::path('review.json'))) {
                Submissions::review($submission);
            }
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => $e->getMessage()], 500);
        }

        return parent::state();
    }

    public function settings(Request $r, ?Submission $submission = null): JsonResponse
    {
        $res = parent::settings($r);
        Activity::log($submission, 'disclosure.' . ($r->json('hide') ? 'hidden' : 'visible'));
        $this->after($submission);

        return $res;
    }

    public function rules(Request $r, ?Submission $submission = null): JsonResponse
    {
        $res = parent::rules($r);
        Activity::log($submission, 'rules.changed');
        $this->after($submission);

        return $res;
    }

    public function generate(Request $r, ?Submission $submission = null): JsonResponse
    {
        $res = parent::generate($r);
        $this->after($submission);
        $submission->refresh();
        Activity::log($submission, 'review.issued', "{$submission->decision} · {$submission->comment_count} comments" . ($submission->engineer ? " · {$submission->engineer}" : ''));

        return $res;
    }

    public function output(...$args): BinaryFileResponse|JsonResponse
    {
        return parent::output((string) end($args));
    }
}
