<?php

namespace App\Http\Controllers;

use App\Review\Extractor;
use App\Review\Reviewer;
use App\Review\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The demo's API: the browser drives the work in short requests (upload in chunks, read a batch of
 * pages per request) so it runs on shared/free hosting with a 30–60 s request limit.
 */
class DemoController extends Controller
{
    private const UPLOAD_CHUNK_BYTES = 1048576;

    public function index()
    {
        return view('app', ['chunk' => self::UPLOAD_CHUNK_BYTES]);
    }

    /** State for the UI. When client details are hidden the real names never leave the server. */
    public function state(): JsonResponse
    {
        return $this->json($this->rawState());
    }

    private function hide(array $cfg): bool
    {
        if (config('demo.lock_disclosure')) {
            return true;
        }

        return (bool) (Store::read('settings.json', [])['hide'] ?? $cfg['disclosure']['hide'] ?? false);
    }

    private function rawState(): array
    {
        $cfg = Store::rules();
        $ex = Store::read('extracted.json');
        $sample = Store::samplePath();

        return [
            'hide' => $this->hide($cfg),
            'locked' => (bool) config('demo.lock_disclosure'),
            'aliases' => $cfg['disclosure']['aliases'] ?? [],
            'disclosure' => [
                'categories' => $cfg['disclosure']['categories'] ?? [],
                'aliases' => count($cfg['disclosure']['aliases'] ?? []),
                'patterns' => count($cfg['disclosure']['redactPatterns'] ?? []),
            ],
            'project' => $cfg['project'],
            'rules' => $cfg['rules'],
            'linkedSubmittals' => $cfg['linkedSubmittals'] ?? [],
            'review' => Store::read('review.json'),
            'source' => $ex['sourceName'] ?? null,
            'sample' => $sample ? [
                'name' => 'CW5.9-DAE-NCC-REM-TS-MEP-002 – Technical Submittal for LV Switchgear Panel GA Drawing.pdf',
                'sizeMb' => round(filesize($sample) / 1048576, 1),
            ] : null,
        ];
    }

    private function json(array $st): JsonResponse
    {
        if ($st['hide']) {
            $aliases = $st['aliases'];
            $st['aliases'] = [];
            $text = json_encode($st, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            foreach ($aliases as [$real, $alias]) {
                $text = str_replace(substr(json_encode($real, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1), substr(json_encode($alias, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1), $text);
            }

            return response()->json(json_decode($text, true))->header('Cache-Control', 'no-store');
        }

        return response()->json($st)->header('Cache-Control', 'no-store');
    }

    private function fail(string $msg, int $code = 400): JsonResponse
    {
        return response()->json(['error' => $msg], $code);
    }

    /** Start a review of the sample submittal (copied to the server by FTP). */
    public function startSample(): JsonResponse
    {
        $file = Store::samplePath();
        if (! $file) {
            return $this->fail('Sample submittal is not on this server');
        }

        return $this->begin($file, $this->rawState()['sample']['name']);
    }

    /** Receive an uploaded PDF in chunks (shared hosts limit the size of one request). */
    public function upload(Request $r): JsonResponse
    {
        $index = (int) $r->query('index', 0);
        $total = max(1, (int) $r->query('total', 1));
        $name = basename((string) $r->query('name', 'upload.pdf'));
        $part = Store::dir('uploads') . '/upload.part';
        $data = $r->getContent();
        if (strlen($data) > self::UPLOAD_CHUNK_BYTES + 1024) {
            return $this->fail('Chunk too large');
        }
        if ($index === 0) {
            if (! str_starts_with($data, '%PDF')) {
                return $this->fail('Not a PDF file');
            }
            file_put_contents($part, $data);
        } else {
            file_put_contents($part, $data, FILE_APPEND);
        }
        if ($index < $total - 1) {
            return response()->json(['received' => $index + 1]);
        }
        $file = Store::dir('uploads') . '/submittal-upload.pdf';
        rename($part, $file);

        return $this->begin($file, $name);
    }

    private function begin(string $file, string $name): JsonResponse
    {
        try {
            Store::delete('review.json');
            $job = Extractor::start($file, $name);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return response()->json(['total' => $job['total']]);
    }

    /** Read the next batch of pages. */
    public function step(): JsonResponse
    {
        @set_time_limit(120);
        try {
            return response()->json(Extractor::step((float) config('demo.step_seconds', 12)));
        } catch (\Throwable $e) {
            report($e);

            return $this->fail($e->getMessage(), 500);
        }
    }

    /** Apply the rules and build the issued PDF. */
    public function review(): JsonResponse
    {
        return $this->rebuild(fn () => Store::delete('overrides.json'));
    }

    private function rebuild(?callable $before = null): JsonResponse
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');
        try {
            if ($before) {
                $before();
            }
            if (Store::read('extracted.json')) {
                Reviewer::run();
            }
        } catch (\Throwable $e) {
            report($e);

            return $this->fail($e->getMessage(), 500);
        }

        return $this->state();
    }

    /** Client disclosure on/off: rebuild the PDF with or without redaction (keeps the engineer's edits). */
    public function settings(Request $r): JsonResponse
    {
        if (config('demo.lock_disclosure')) {
            return $this->state();
        }

        return $this->rebuild(fn () => Store::write('settings.json', ['hide' => (bool) $r->json('hide')]));
    }

    /** Save rule edits (on/off, thresholds) and re-run the check on the already-extracted data. */
    public function rules(Request $r): JsonResponse
    {
        $edits = $r->json()->all();

        return $this->rebuild(function () use ($edits) {
            $cfg = Store::rules();
            foreach ($cfg['rules'] as &$rule) {
                foreach ($edits as $e) {
                    if (($e['id'] ?? null) !== $rule['id']) {
                        continue;
                    }
                    $rule['active'] = (bool) ($e['active'] ?? true);
                    if (isset($e['expected']) && $e['expected'] !== '' && is_numeric($rule['expected'] ?? null)) {
                        $rule['expected'] = +$e['expected'];
                    }
                }
            }
            unset($rule);
            Store::saveRules($cfg);
            Store::delete('overrides.json');
        });
    }

    /** Engineer approved: rebuild the PDF with their comments, decision and name. */
    public function generate(Request $r): JsonResponse
    {
        $o = $r->json()->all();

        return $this->rebuild(fn () => Store::write('overrides.json', [
            'comments' => array_values(array_map(fn ($c) => array_intersect_key((array) $c, array_flip(['text', 'status', 'manual', 'clause', 'panels', 'pages', 'rule', 'origin'])), (array) ($o['comments'] ?? []))),
            'decision' => in_array($o['decision'] ?? '', Reviewer::DECISIONS, true) ? $o['decision'] : null,
            'engineer' => mb_substr((string) ($o['engineer'] ?? ''), 0, 80),
            'final' => true,
        ]));
    }

    /** The issued PDF (supports HTTP range requests, so the viewer loads only the pages it shows). */
    public function output(string $name): BinaryFileResponse|JsonResponse
    {
        $current = Store::read('review.json')['pdfName'] ?? null;
        $file = Store::path('out/' . $current);
        if (! $current || $name !== $current || ! is_file($file)) {
            return $this->fail('not found', 404);
        }

        return response()->file($file, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'no-store']);
    }
}
