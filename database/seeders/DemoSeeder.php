<?php

namespace Database\Seeders;

use App\Review\Extractor;
use App\Review\Reviewer;
use App\Review\Store;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;

/**
 * Restores the demo after the host wiped its files (e.g. Render free: every restart starts empty).
 * No database: the demo's data are files in storage/app/demo, rebuilt here from the repo + settings.
 *
 *   php artisan db:seed --force
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // project, rules and disclosure config (copied from resources/demo/rules.json if missing)
        Store::rules();

        if (! is_file(Store::path('settings.json'))) {
            Store::write('settings.json', ['hide' => (bool) config('demo.hide_default', true)]);
        }

        // the sample submittal is confidential, so it is never in git: fetch it from a private link if one is set
        $sample = Store::path('sample/submittal.pdf');
        $url = (string) config('demo.sample_url');
        if (! Store::samplePath() && $url !== '') {
            Store::dir('sample');
            $this->say('Downloading the sample submittal…');
            $res = Http::timeout(300)->withOptions(['sink' => $sample . '.tmp'])->get($url);
            $head = is_file($sample . '.tmp') ? (string) file_get_contents($sample . '.tmp', false, null, 0, 1024) : '';
            if ($res->successful() && str_contains($head, '%PDF')) {
                rename($sample . '.tmp', $sample);
            } else {
                @unlink($sample . '.tmp');
                $this->say('DEMO_SAMPLE_URL did not return a PDF (HTTP ' . $res->status() . ') — check that the link downloads the file directly.');
            }
        }

        // pre-run the review so the demo opens with a finished review
        $file = Store::samplePath();
        if ($file && ! Store::read('review.json')) {
            $this->say('Reviewing the sample submittal…');
            Extractor::start($file, 'CW5.9-DAE-NCC-REM-TS-MEP-002 – Technical Submittal for LV Switchgear Panel GA Drawing.pdf');
            while (! Extractor::step(30)['done']) {
            }
            $r = Reviewer::run();
            $this->say("Ready: {$r['pageCount']} pages, " . count($r['comments']) . ' comments.');
        }
    }

    private function say(string $msg): void
    {
        $this->command?->info($msg);
    }
}
