<?php

namespace App\V2;

use App\Models\V2\Activity;
use App\Models\V2\Submission;
use App\Review\Extractor;
use App\Review\Reviewer;
use App\Review\Store;
use Illuminate\Support\Facades\File;

/**
 * Runs the review engine for one v2 submission, inside its own private folder
 * (storage/app/v2/submissions/{id}) so it never touches the v1 demo.
 */
final class Submissions
{
    /** Prepare the review folder: project details from the form + the rule template. */
    public static function prepare(Submission $s): void
    {
        File::ensureDirectoryExists($s->dir());
        $cfg = json_decode((string) file_get_contents(resource_path('demo/rules.json')), true);
        $company = Site::t(Site::company(), 'name');
        $cfg['project'] = [
            'name' => $s->project_name,
            'clientRef' => '',
            'engineerRef' => '',
            'submittalNo' => $s->submittal_no ?: 'SUB-' . $s->code,
            'revision' => 0,
            'title' => $s->title ?: 'Technical submittal',
            'vendor' => $s->client_company ?: $s->client_name,
            'specDocument' => $cfg['project']['specDocument'] ?? 'Project specification',
            'discipline' => $s->discipline,
            'submittedAt' => $s->created_at?->format('d-M-Y') ?? date('d-M-Y'),
            'purpose' => 'Submitted for review and approval',
            'parties' => array_filter(['consultant' => $company, 'mainContractor' => $s->client_company]),
        ];
        // the reference project's linked submittal does not apply to a new submission
        $cfg['linkedSubmittals'] = [];
        // hide this submission's own identifying details too
        $own = array_filter([
            [$s->project_name, 'Project A'],
            [$s->client_company, 'Contractor'],
            [$s->client_name, 'Contact person'],
            [$s->submittal_no, 'PRJ-' . $s->code],
            [$s->client_email, 'contact@example.com'],
        ], fn ($p) => is_string($p[0]) && mb_strlen(trim($p[0])) >= 3);
        $aliases = array_merge($own, $cfg['disclosure']['aliases'] ?? []);
        usort($aliases, fn ($a, $b) => mb_strlen($b[0]) <=> mb_strlen($a[0]));
        $cfg['disclosure']['aliases'] = array_values($aliases);
        foreach ([$s->project_name, $s->client_company] as $term) {
            if (is_string($term) && mb_strlen(trim($term)) >= 4) {
                $cfg['disclosure']['redactPatterns'][] = str_replace('\\ ', ' *', preg_quote(trim($term), '/'));
            }
        }
        $cfg['disclosure']['hide'] = (bool) Site::review()['hide_default'];
        Store::using($s->dir(), function () use ($cfg) {
            Store::saveRules($cfg);
            if (! is_file(Store::path('settings.json'))) {
                Store::write('settings.json', ['hide' => (bool) $cfg['disclosure']['hide']]);
            }
        });
    }

    /** Start (or restart) reading the original PDF. */
    public static function start(Submission $s): array
    {
        if (! is_file($s->dir() . '/rules.json')) {
            self::prepare($s);
        }
        $job = Store::using($s->dir(), fn () => Extractor::start($s->originalPath(), $s->file_name ?: 'submittal.pdf'));
        Store::using($s->dir(), fn () => Store::delete('review.json'));
        $s->update(['status' => 'analysing', 'page_count' => $job['total']]);
        Activity::log($s, 'analysis.started', "{$job['total']} pages");

        return $job;
    }

    /** Read the next batch of pages; when all are read, run the rules and build the draft. */
    public static function step(Submission $s, float $budget): array
    {
        $r = Store::using($s->dir(), fn () => Extractor::step($budget));
        if ($r['done'] && empty($r['busy']) && ! is_file($s->dir() . '/review.json')) {
            self::review($s);
        }

        return $r;
    }

    public static function review(Submission $s): array
    {
        $review = Store::using($s->dir(), fn () => Reviewer::run());
        self::sync($s, $review);

        return $review;
    }

    /** Copy the review's headline numbers onto the submission row. */
    public static function sync(Submission $s, array $review): void
    {
        $was = $s->status;
        $s->fill([
            'decision' => $review['decision'],
            'comment_count' => count($review['comments']),
            'page_count' => $review['pageCount'],
            'engineer' => $review['engineerName'] ?: $s->engineer,
        ]);
        if (! $s->analysed_at) {
            $s->analysed_at = now();
        }
        if (! empty($review['final'])) {
            $s->status = 'issued';
            $s->issued_at ??= now();
        } elseif (in_array($s->status, ['received', 'analysing', 'uploading'], true)) {
            $s->status = 'review';
        }
        $s->save();
        if ($was !== $s->status) {
            Activity::log($s, 'status.' . $s->status, $s->status === 'review' ? count($review['comments']) . ' comments drafted · suggested ' . $review['suggested'] : null);
        }
    }
}
