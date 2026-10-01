<?php

namespace App\Console\Commands;

use App\Review\Extractor;
use App\Review\Reviewer;
use App\Review\Store;
use Illuminate\Console\Command;

class ReviewSubmittal extends Command
{
    protected $signature = 'demo:review {file? : PDF to review (default: the sample submittal)} {--hide= : 1 = hide client details, 0 = show}';

    protected $description = 'Run the full review on a submittal PDF (same as the web app)';

    public function handle(): int
    {
        $file = $this->argument('file') ?? Store::samplePath();
        if (! $file || ! is_file($file)) {
            $this->error('No PDF given and no sample at storage/app/demo/sample/submittal.pdf');

            return 1;
        }
        if ($this->option('hide') !== null) {
            Store::write('settings.json', ['hide' => (bool) $this->option('hide')]);
        }
        $t0 = microtime(true);
        $job = Extractor::start(realpath($file), basename($file));
        $bar = $this->output->createProgressBar($job['total']);
        do {
            $s = Extractor::step(5);
            $bar->setProgress($s['page']);
        } while (! $s['done']);
        $bar->finish();
        $this->newLine();
        $r = Reviewer::run();
        $this->info(sprintf('%d pages · %d panels · %d comments · %s · %s (%.1fs)', $r['pageCount'], $r['stats']['panels'], count($r['comments']), $r['decision'], $r['pdfName'], microtime(true) - $t0));
        foreach ($r['comments'] as $c) {
            $this->line("{$c['no']} {$c['status']} | {$c['text']}");
        }
        $this->line('Redacted: ' . json_encode($r['redacted']) . ' → ' . Store::path('out/' . $r['pdfName']));

        return 0;
    }
}
