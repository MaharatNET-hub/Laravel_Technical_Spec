<?php

namespace Tests\Feature;

use App\Pdf\ContentWalker;
use App\Pdf\Reader;
use App\Review\Extractor;
use App\Review\Reviewer;
use App\Review\Store;
use Tests\TestCase;

class ReviewPipelineTest extends TestCase
{
    private string $pdf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->useStoragePath(sys_get_temp_dir() . '/sr-test-' . getmypid());
        @mkdir(storage_path('app'), 0777, true);
        $this->pdf = storage_path('app/submittal.pdf');
        file_put_contents($this->pdf, self::submittal());
    }

    /** A tiny two-panel submittal: cover, data sheet and part list per panel, with client names in the title block. */
    public static function submittal(): string
    {
        $pages = [];
        foreach ([['EMDB-A-1', 'IP-43', '1.5'], ['SMDB-A-1', 'IP-54', '2.5']] as [$panel, $ip, $wire]) {
            $title = fn ($kind) => "BT /F1 9 Tf 40 60 Td (CITY WALK PHASE 5 - PLOT NO 5.09) Tj ET BT /F1 9 Tf 300 48 Td (PIONEER DYNAMICS SWITCHGEAR) Tj ET "
                . "BT /F1 9 Tf 600 60 Td (PANEL NAME) Tj ET BT /F1 9 Tf 600 48 Td ($panel) Tj ET BT /F1 9 Tf 600 36 Td (PAGE TITLE: $kind) Tj ET ";
            $pages[] = $title('COVER SHEET') . "BT /F1 9 Tf 100 400 Td (STANDARD EQUIPMENT TYPE) Tj ET BT /F1 9 Tf 100 380 Td (FORM-4, TYPE-6, $ip) Tj ET";
            $ds = $title('DATA SHEET') . 'BT /F1 9 Tf 100 520 Td (DATA SHEET) Tj ET ';
            $y = 480;
            foreach (['IP-43', 'IP-54', 'IP-65'] as $o) {
                $ds .= "BT /F1 9 Tf 100 $y Td ($o) Tj ET " . ($o === $ip ? "BT /F1 9 Tf 200 $y Td (X) Tj ET " : '');
                $y -= 16;
            }
            $ds .= "BT /F1 9 Tf 100 $y Td (FORM-4, TYPE-6) Tj ET BT /F1 9 Tf 200 $y Td (X) Tj ET ";
            $y -= 16;
            foreach (['1.5', '2.5'] as $o) {
                $ds .= ($o === $wire ? "BT /F1 9 Tf 300 $y Td (X) Tj ET " : '') . "BT /F1 9 Tf 312 $y Td ($o) Tj ET BT /F1 9 Tf 330 $y Td (SQ.MM) Tj ET ";
                $y -= 16;
            }
            $pages[] = $ds;
            $pages[] = $title('MATERIAL LIST') . 'BT /F1 9 Tf 100 500 Td (MATERIAL DESC) Tj ET BT /F1 9 Tf 100 480 Td (MCCB 400A) Tj ET';
        }
        $objs = [1 => '<</Type /Catalog /Pages 2 0 R>>', 3 => '<</Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding>>'];
        $kids = [];
        foreach ($pages as $i => $content) {
            $p = 4 + 2 * $i;
            $kids[] = "$p 0 R";
            $objs[$p] = "<</Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources <</Font <</F1 3 0 R>>>> /Contents " . ($p + 1) . ' 0 R>>';
            $z = gzcompress($content);
            $objs[$p + 1] = '<</Length ' . strlen($z) . " /Filter /FlateDecode>>\nstream\n$z\nendstream";
        }
        $objs[2] = '<</Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . '>>';
        ksort($objs);
        $out = "%PDF-1.4\n";
        $off = [];
        foreach ($objs as $n => $o) {
            $off[$n] = strlen($out);
            $out .= "$n 0 obj\n$o\nendobj\n";
        }
        $x = strlen($out);
        $out .= 'xref' . "\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($off as $o) {
            $out .= sprintf("%010d 00000 n \n", $o);
        }

        return $out . 'trailer <</Size ' . (count($objs) + 1) . " /Root 1 0 R>>\nstartxref\n$x\n%%EOF\n";
    }

    private function runReview(bool $hide): array
    {
        Store::write('settings.json', ['hide' => $hide]);
        Extractor::start($this->pdf, 'submittal.pdf');
        while (! Extractor::step(5)['done']) {
        }

        return Reviewer::run();
    }

    private static function allText(string $file): string
    {
        $r = Reader::open($file);
        $text = '';
        foreach ($r->pages() as $p) {
            $w = new ContentWalker($r);
            $w->page($p['dict'], $p['resources']);
            $text .= implode(' ', array_column($w->items, 's')) . "\n";
        }

        return $text;
    }

    public function test_finds_the_non_compliances(): void
    {
        $r = $this->runReview(false);
        $rules = array_column($r['comments'], 'rule');
        $this->assertSame(['P1', 'R1', 'R4', 'R5', 'R6'], $rules);
        $this->assertSame('Revise / Resubmit', $r['decision']);
        $this->assertSame(['transmittal', 'transmittal2', 'sheet', 'client'], array_column($r['issued'], 'key'));
        $this->assertSame(6 + 4, count(Reader::open(Store::path('out/' . $r['pdfName']))->pages()));
        $this->assertStringContainsString('CITY WALK', self::allText(Store::path('out/' . $r['pdfName'])));
    }

    public function test_client_disclosure_removes_names_from_the_pdf(): void
    {
        $r = $this->runReview(true);
        $this->assertStringStartsWith('PRJ-TS-MEP-002', $r['pdfName']);
        $text = self::allText(Store::path('out/' . $r['pdfName']));
        $this->assertDoesNotMatchRegularExpression('/CITY WALK|PIONEER|Dubai Holding|Naresco|CW5\.9/i', $text);
        $this->assertStringContainsString('EMDB-A-1', $text); // the engineering content stays
        $this->assertStringContainsString('CLIENT DETAILS WITHHELD', $text);
        $this->assertGreaterThan(0, $r['redacted']['text']);
    }

    public function test_state_api_masks_names(): void
    {
        $this->runReview(true);
        $json = $this->get('/api/state')->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/City Walk|Pioneer|Dubai Holding|Dewan/', $json);
        $this->get('/')->assertOk()->assertSee('Submittal Review');
    }
}
