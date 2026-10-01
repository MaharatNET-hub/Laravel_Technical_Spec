<?php

namespace App\Review;

use App\Pdf\Canvas;
use App\Pdf\Dict;
use App\Pdf\Name;
use App\Pdf\Reader;
use App\Pdf\Ref;
use App\Pdf\Stream;
use App\Pdf\Writer;

/**
 * Step 2 — apply the rules to the extracted panels, then produce review.json and the issued PDF:
 * transmittal (A–D), comment sheet, client comments, and the submittal stamped and marked up.
 * Client disclosure: names are swapped for aliases and identifying text/logos are removed from the PDF.
 */
final class Reviewer
{
    private const RED = [0.83, 0.1, 0.1], AMBER = [0.85, 0.47, 0], INK = [0.1, 0.1, 0.12], GREY = [0.45, 0.45, 0.5], WHITE = [1, 1, 1], SHADE = [0.93, 0.93, 0.95], BLACK = [0.13, 0.14, 0.16];

    public const DECISIONS = ['Approved', 'Approved as noted', 'Revise / Resubmit', 'Rejected', 'Approved as noted / Resubmit', 'No action required / for information only'];

    private array $cfg;

    private bool $hide;

    private array $ex;

    private array $panels;

    public static function run(): array
    {
        return (new self)->build();
    }

    private function __construct()
    {
        $this->cfg = Store::rules();
        $settings = Store::read('settings.json', []);
        $this->hide = (bool) (config('demo.lock_disclosure') ? true : ($settings['hide'] ?? $this->cfg['disclosure']['hide'] ?? false));
        $this->ex = Store::read('extracted.json') ?? throw new \RuntimeException('Nothing extracted yet');
        $this->panels = array_values($this->ex['panels']);
    }

    public function mask(?string $s): string
    {
        $s = (string) $s;
        if (! $this->hide) {
            return $s;
        }
        foreach ($this->cfg['disclosure']['aliases'] ?? [] as [$real, $alias]) {
            $s = str_replace($real, $alias, $s);
        }

        return $s;
    }

    private static function ascii(?string $s): string
    {
        return str_replace(['≥', '“', '”', '‘', '’', '—'], ['>=', '"', '"', "'", "'", '-'], (string) $s);
    }

    // ---------------------------------------------------------------- helpers

    private static function num(?string $s): ?float
    {
        return preg_match('/(\d+(?:\.\d+)?)/', (string) $s, $m) ? (float) $m[1] : null;
    }

    private static function listVals(?array $v): array
    {
        if (isset($v['list'])) {
            return array_column($v['list'], 'value');
        }

        return isset($v['value']) ? [$v['value']] : [];
    }

    private static function parseForm(?string $s): ?array
    {
        if (! preg_match('/FORM-?\s*(\d\w?)/i', (string) $s, $f)) {
            return null;
        }

        return ['form' => $f[1], 'type' => preg_match('/TYPE-?\s*(\d)/i', (string) $s, $t) ? $t[1] : null];
    }

    private static function ipNum(?string $s): ?int
    {
        return preg_match('/IP-?\s*(\d\d)/i', (string) $s, $m) ? (int) $m[1] : null;
    }

    private static function firstPage(array $p, string $type): ?int
    {
        foreach ($p['pages'] as $x) {
            if ($x['type'] === $type) {
                return $x['page'];
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- rule evaluation

    private function evaluate(array $rule, array $p): array
    {
        $v = $p['values'];
        $ev = fn ($page, $bbox) => ['page' => $page, 'bbox' => $bbox];
        switch ($rule['operator']) {
            case 'gte':
                $vals = self::listVals($v[$rule['attribute']] ?? null);
                if (! $vals) {
                    return ['status' => 'unclear', 'actual' => 'not found'];
                }
                $n = self::ipNum($vals[0]);
                $e = $v[$rule['attribute']]['list'][0] ?? null;

                return ['status' => $n !== null && $n >= $rule['expected'] ? 'pass' : 'fail', 'actual' => $vals[0], 'evidence' => $ev($v[$rule['attribute']]['page'] ?? null, $e['bbox'] ?? null)];
            case 'form':
                $vals = self::listVals($v['form'] ?? null);
                if (! $vals) {
                    return ['status' => 'unclear', 'actual' => 'not found'];
                }
                $f = self::parseForm($vals[0]);
                $e = $ev($v['form']['page'] ?? null, $v['form']['list'][0]['bbox'] ?? null);
                if (! $f) {
                    return ['status' => 'unclear', 'actual' => $vals[0], 'evidence' => $e];
                }
                if ($f['form'] !== $rule['expected']['form']) {
                    return ['status' => 'fail', 'actual' => $vals[0], 'evidence' => $e];
                }
                if (! $f['type']) {
                    return ['status' => 'unclear', 'actual' => $vals[0], 'evidence' => $e];
                }

                return ['status' => $f['type'] === $rule['expected']['type'] ? 'pass' : 'fail', 'actual' => $vals[0], 'evidence' => $e];
            case 'minAll':
                $opts = $v['auxWire']['list'] ?? [];
                if (! $opts) {
                    return ['status' => 'unclear', 'actual' => 'not found'];
                }
                $bad = array_values(array_filter($opts, fn ($o) => self::num($o['value']) < $rule['expected']));
                $actual = implode(' / ', array_column($opts, 'value'));

                return $bad
                    ? ['status' => 'fail', 'actual' => implode(', ', array_column($bad, 'value')), 'shown' => $actual, 'evidence' => $ev($v['auxWire']['page'], $bad[0]['bbox'])]
                    : ['status' => 'pass', 'actual' => $actual];
            case 'present':
                $h = ! empty($v['hasHeater']);
                $t = ! empty($v['hasThermostat']);
                $missing = array_filter([! $h ? 'heater' : null, ! $t ? 'thermostat' : null]);

                return ['status' => $h && $t ? 'pass' : 'fail', 'actual' => $h && $t ? 'heater + thermostat' : 'no ' . implode(' / ', $missing), 'evidence' => $ev(self::firstPage($p, 'MATERIAL') ?? self::firstPage($p, 'SLD'), null)];
            case 'consistent':
                $ips = array_values(array_filter(array_map(fn ($s) => self::ipNum($s), [$v['declared']['value'] ?? null, self::listVals($v['ip'] ?? null)[0] ?? null, $v['gaIp']['value'] ?? null]), fn ($x) => $x !== null));
                $forms = array_values(array_filter(array_map(fn ($s) => self::parseForm($s)['form'] ?? null, [$v['declared']['value'] ?? null, self::listVals($v['form'] ?? null)[0] ?? null, $v['gaForm']['value'] ?? null])));
                $same = fn ($a) => count(array_unique($a)) <= 1;

                return ['status' => $same($ips) && $same($forms) ? 'pass' : 'fail', 'actual' => 'IP ' . implode('/', array_unique($ips)) . ' · Form ' . implode('/', array_unique($forms))];
            case 'noAnomaly':
                $a = array_values(array_filter($this->ex['anomalies'], fn ($x) => $x['section'] === $p['name']));
                if (! $a) {
                    return ['status' => 'pass', 'actual' => count($p['pages']) . ' pages'];
                }
                $pages = implode(', ', array_column($a, 'page'));

                return ['status' => 'unclear', 'actual' => "p.$pages titled {$a[0]['titleBlock']}", 'anomaly' => ['pages' => $pages, 'section' => $p['name'], 'titleBlock' => $a[0]['titleBlock']], 'evidence' => $ev($a[0]['page'], null)];
        }

        return ['status' => 'unclear', 'actual' => 'unknown rule'];
    }

    private function describePanels(array $names, array $kind): string
    {
        $all = array_column(array_filter($this->panels, fn ($p) => in_array($p['kind'], $kind, true)), 'name');
        if (count($names) === count($all) && count($all) > 3) {
            return 'all ' . count($names) . ' ' . implode('/', $kind) . 's';
        }
        $missing = array_values(array_diff($all, $names));
        if (count($names) > 6 && count($missing) <= 4) {
            return 'all ' . implode('/', $kind) . 's except ' . implode(', ', $missing) . ' (' . count($names) . ' panels)';
        }

        return implode(', ', $names);
    }

    // ---------------------------------------------------------------- build

    private function build(): array
    {
        $cfg = $this->cfg;
        $activeRules = array_values(array_filter($cfg['rules'], fn ($r) => ($r['active'] ?? true) !== false));
        $results = [];
        foreach ($this->panels as $p) {
            foreach ($activeRules as $r) {
                if (! (in_array('*', $r['appliesTo'], true) || in_array($p['kind'], $r['appliesTo'], true))) {
                    continue;
                }
                $results[] = ['panel' => $p['name'], 'kind' => $p['kind'], 'rule' => $r['id']] + $this->evaluate($r, $p);
            }
        }

        // group into comments
        $comments = [];
        foreach ($cfg['linkedSubmittals'] ?? [] as $l) {
            if (! empty($l['contractorResponded'])) {
                continue;
            }
            $comments[] = ['origin' => 'procedural', 'rule' => 'P1', 'status' => 'fail', 'clause' => 'Linked submittal register', 'panels' => [], 'pages' => [],
                'text' => "{$l['subject']} ({$l['ref']}) - {$l['reviewedBy']} comments ({$l['openComments']}) not replied; it is mandatory to respond prior to fabrication of equipment (attached)."];
        }
        foreach ($activeRules as $r) {
            foreach (['fail', 'unclear'] as $status) {
                $hits = array_values(array_filter($results, fn ($x) => $x['rule'] === $r['id'] && $x['status'] === $status));
                if (! $hits) {
                    continue;
                }
                $names = array_column($hits, 'panel');
                $actual = implode(' / ', array_unique(array_column($hits, 'actual')));
                $a = $hits[0]['anomaly'] ?? [];
                $kind = in_array('*', $r['appliesTo'], true) ? ['EMDB', 'SMDB', 'DB'] : $r['appliesTo'];
                $text = strtr($r['comment'], [
                    '{actual}' => self::ipNum($actual) !== null && $r['attribute'] === 'ip' ? (string) self::ipNum($actual) : $actual,
                    '{section}' => $a['section'] ?? $r['clause']['section'], '{path}' => $r['clause']['path'],
                    '{panels}' => $this->describePanels($names, $kind), '{pages}' => $a['pages'] ?? '', '{titleBlock}' => $a['titleBlock'] ?? '',
                ]);
                $comments[] = ['origin' => 'rule', 'rule' => $r['id'], 'status' => $status, 'text' => self::ascii($text),
                    'clause' => $r['clause']['section'] === '—' ? $r['clause']['path'] : "{$r['clause']['section']} › {$r['clause']['path']}",
                    'panels' => $names, 'pages' => array_values(array_filter(array_map(fn ($h) => $h['evidence']['page'] ?? null, $hits)))];
            }
        }
        foreach ($comments as $i => &$c) {
            $c['no'] = $i + 1;
        }
        unset($c);

        $hasFail = (bool) array_filter($comments, fn ($c) => $c['status'] === 'fail');
        $suggested = $hasFail ? 'Revise / Resubmit' : ($comments ? 'Approved as noted' : 'Approved');
        // the engineer's edits from the UI (comment text, manual comments, decision, name) override the draft
        $ov = Store::read('overrides.json');
        $decision = $ov['decision'] ?? $suggested;
        $sheet = [];
        foreach ($ov['comments'] ?? $comments as $i => $c) {
            $c['no'] = $i + 1;
            $c['text'] = self::ascii($c['text'] ?? '');
            $sheet[] = $c;
        }
        $isFinal = ! empty($ov['final']);

        // what the consultant actually wrote in the reference result (for the side-by-side)
        $engineer = [
            ['no' => 1, 'text' => 'Material Submittal Client Comments not replied and it is mandatory to respond prior to fabrication of Equipment (ATTACHED). SLD Submission Client Concerns need to be marked here for attendance as well.', 'rule' => 'P1'],
            ['no' => 2, 'text' => 'Contractor to justify not providing ACH and HST as per project requirements.', 'rule' => 'R6'],
            ['no' => 3, 'text' => 'Vendor to clarify is SMDB Type 2 of Form 2 or not, data in submission not clear.', 'rule' => 'R4'],
            ['no' => 4, 'text' => 'ESMDB need to be IP54 not 43.', 'rule' => 'R1'],
            ['no' => '✎', 'text' => 'Hand mark-up on EMDB-A-1 data sheet: "Not accepted" next to auxiliary wiring 1.5 sq.mm.', 'rule' => 'R5'],
        ];

        $pdf = $this->pdf($results, $sheet, $decision, $isFinal, $ov);

        $review = [
            'project' => $cfg['project'], 'rules' => $cfg['rules'], 'hidden' => $this->hide, 'suggested' => $suggested, 'decision' => $decision,
            'final' => $isFinal, 'engineerName' => $ov['engineer'] ?? '', 'draftComments' => $comments, 'comments' => $sheet, 'results' => $results, 'engineer' => $engineer,
            'panels' => array_map(fn ($p) => ['name' => $p['name'], 'kind' => $p['kind'], 'first' => $p['pages'][0]['page'], 'last' => end($p['pages'])['page'], 'pages' => $p['pages']], $this->panels),
            'anomalies' => $this->ex['anomalies'], 'pageCount' => $this->ex['pageCount'], 'seconds' => $this->ex['seconds'] ?? null,
            'stats' => ['panels' => count($this->panels), 'checks' => count($results), 'fail' => count(array_filter($results, fn ($r) => $r['status'] === 'fail')),
                'unclear' => count(array_filter($results, fn ($r) => $r['status'] === 'unclear')), 'markedPages' => count($pdf['marks'])],
        ] + $pdf;
        Store::write('review.json', $review);

        return $review;
    }

    // ---------------------------------------------------------------- PDF

    private function pdf(array $results, array $sheet, string $decision, bool $isFinal, ?array $ov): array
    {
        $cfg = $this->cfg;
        $P = $cfg['project'];
        $parties = $P['parties'] ?? [];
        $hide = $this->hide;
        $T = fn (?string $s) => self::ascii($this->mask($s));
        $today = date('Y-m-d');
        $withheld = 'CLIENT DETAILS WITHHELD - CLIENT DISCLOSURE ON';

        $r = Reader::open($this->ex['source']);
        $srcPages = $r->pages();
        $w = new Writer($r);
        if ($hide) {
            $w->stripKeys = ['Metadata', 'PieceInfo', 'Thumb', 'Annots'];
        }
        $fonts = [];
        foreach (Canvas::fontDicts() as $k => $d) {
            $fonts[$k] = $w->add($d);
        }
        $rootDict = new Dict; // the new page-tree root, filled in at the end
        $newRoot = $w->add($rootDict);
        $generated = []; // generated pages, in order: [canvas, width, height]
        $issued = [];

        $addPage = function (float $pw, float $ph, string $key, string $title) use (&$generated, &$issued) {
            $c = new Canvas;
            $generated[] = ['c' => $c, 'w' => $pw, 'h' => $ph];
            if ($key !== '') {
                $issued[] = ['key' => $key, 'title' => $title, 'page' => count($generated)];
            }

            return $c;
        };
        $box = function (Canvas $c, $x, $y, bool $on, $size = 9) {
            $c->rect($x, $y - 1, $size, $size, ['border' => self::INK, 'width' => 0.8]);
            if ($on) {
                $c->text('X', $x + 1.6, $y + 0.4, $size - 1, self::RED, true);
            }
        };
        $footer = function (Canvas $c, $pw) use ($hide, $withheld) {
            $c->text('Generated automatically from the submittal; the engineer reviews, edits and signs.', 40, 24, 7.5, self::GREY);
            if ($hide) {
                $c->text($withheld, $pw - 40 - Canvas::width($withheld, 7.5, true), 24, 7.5, self::GREY, true);
            }
        };
        // party logos are not reproduced: names only (and masked when client disclosure is on)
        $partyRow = function (Canvas $c, $pw, $y) use ($parties, $T) {
            $list = array_values(array_filter([['Client', $parties['client'] ?? null], ['Developer', $parties['developer'] ?? null], ['Consultant', $parties['consultant'] ?? null], ['Main contractor', $parties['mainContractor'] ?? null]], fn ($x) => $x[1]));
            $bw = ($pw - 80) / max(count($list), 1);
            foreach ($list as $i => [$k, $v]) {
                $c->rect(40 + $i * $bw, $y - 30, $bw - 6, 30, ['border' => self::GREY, 'width' => 0.6]);
                $c->text(strtoupper($k), 46 + $i * $bw, $y - 11, 6.5, self::GREY, true);
                $c->text(mb_substr($T($v), 0, 34), 46 + $i * $bw, $y - 24, 8.5, self::INK, true);
            }
        };
        $field = function (Canvas $c, $x, $y, $k, $v, $fw = 200) use ($T) {
            $c->text($k, $x, $y, 7, self::GREY, true);
            foreach (array_slice(Canvas::wrap($T($v === null || $v === '' ? '-' : (string) $v), 9, $fw), 0, 3) as $i => $l) {
                $c->text($l, $x, $y - 12 - $i * 11, 9, self::INK);
            }
        };
        $section = function (Canvas $c, $pw, $y, $title) {
            $c->rect(40, $y - 16, $pw - 80, 16, ['fill' => self::SHADE, 'border' => self::INK, 'width' => 0.6]);
            $c->text($title, 46, $y - 12, 9, self::INK, true);
        };

        // ---- transmittal (Form F12.5A): parts A/B from the submittal, C/D the engineer's response
        $pw = 595;
        $ph = 842;
        $c = $addPage($pw, $ph, 'transmittal', 'Transmittal · Parts A/B');
        $c->text('SUBMITTAL TRANSMITTAL', 40, $ph - 52, 16, self::INK, true);
        $c->text('Form F12.5A', $pw - 40 - Canvas::width('Form F12.5A', 9), $ph - 50, 9, self::GREY);
        $partyRow($c, $pw, $ph - 66);
        $y = $ph - 120;
        $section($c, $pw, $y, 'PART A - SUBMITTAL DETAILS (CONTRACTOR)');
        $y -= 34;
        $field($c, 46, $y, 'PROJECT', "{$P['engineerRef']} {$P['name']}", 250);
        $field($c, 320, $y, 'CLIENT REF.', $P['clientRef']);
        $y -= 44;
        $field($c, 46, $y, 'SUBMITTAL NO.', $P['submittalNo'], 250);
        $field($c, 320, $y, 'REVISION', (string) $P['revision']);
        $field($c, 420, $y, 'DATE SUBMITTED', $P['submittedAt'] ?? '');
        $y -= 44;
        $field($c, 46, $y, 'DISCIPLINE', $P['discipline'] ?? 'Electrical');
        $field($c, 320, $y, 'SPECIFICATION', $P['specDocument'], 220);
        $y -= 44;
        $field($c, 46, $y, 'TITLE', $P['title'], 500);
        $y -= 50;
        $field($c, 46, $y, 'SUBMITTED BY', implode(' / ', array_filter([$parties['mainContractor'] ?? null, $parties['mepContractor'] ?? null])), 250);
        $field($c, 320, $y, 'VENDOR / MANUFACTURER', $P['vendor'], 220);
        $y -= 50;
        $section($c, $pw, $y, 'PART B - ATTACHMENTS & PURPOSE');
        $y -= 34;
        $field($c, 46, $y, 'ATTACHMENTS', "Technical submittal, {$this->ex['pageCount']} pages (" . count($this->panels) . ' panels: cover sheets, data sheets, SLD, GA, material lists)', 500);
        $y -= 44;
        foreach (['Submitted for review and approval', 'Submitted for information', 'Resubmitted (revision)'] as $pur) {
            $box($c, 46, $y, $pur === ($P['purpose'] ?? 'Submitted for review and approval'));
            $c->text($pur, 62, $y, 9, self::INK);
            $y -= 16;
        }
        $footer($c, $pw);

        $c = $addPage($pw, $ph, 'transmittal2', 'Transmittal · Parts C/D');
        $c->text("SUBMITTAL TRANSMITTAL - ENGINEER'S RESPONSE", 40, $ph - 52, 14, self::INK, true);
        $c->text($T("{$P['submittalNo']}  Rev. {$P['revision']}"), 40, $ph - 68, 9, self::GREY);
        $y = $ph - 90;
        $section($c, $pw, $y, "PART C - ENGINEER'S COMMENTS");
        $y -= 32;
        $fails = count(array_filter($sheet, fn ($x) => ($x['status'] ?? '') !== 'unclear'));
        $clar = count($sheet) - $fails;
        foreach (Canvas::wrap('See attached Comment Sheet: ' . count($sheet) . " comment(s) ($fails non-compliance, $clar clarification). Marked-up drawings attached; refer to the comment number shown on each mark.", 9.5, $pw - 100) as $l) {
            $c->text($l, 46, $y, 9.5, self::INK);
            $y -= 13;
        }
        $y -= 6;
        foreach (array_slice($sheet, 0, 12) as $s) {
            $ls = array_slice(Canvas::wrap("{$s['no']}. " . $T($s['text']), 8.5, $pw - 110), 0, 2);
            foreach ($ls as $i => $l) {
                $c->text($l, 52, $y - $i * 11, 8.5, self::INK);
            }
            $y -= count($ls) * 11 + 3;
        }
        if (count($sheet) > 12) {
            $c->text('... and ' . (count($sheet) - 12) . ' more on the Comment Sheet', 52, $y, 8.5, self::GREY);
            $y -= 14;
        }
        $y -= 14;
        $section($c, $pw, $y, 'PART D - ACTION');
        $y -= 32;
        foreach (self::DECISIONS as $i => $d) {
            $x = 46 + ($i % 2) * 260;
            $yy = $y - intdiv($i, 2) * 18;
            $box($c, $x, $yy, $d === $decision);
            $c->text($d, $x + 16, $yy, 9.5, $d === $decision ? self::RED : self::INK, $d === $decision);
        }
        $y -= 70;
        if (! $isFinal) {
            $c->text('SUGGESTED BY THE ASSISTANT - NOT YET APPROVED BY THE ENGINEER', 46, $y, 8, self::AMBER, true);
            $y -= 18;
        }
        $field($c, 46, $y, 'ENGINEER', ($ov['engineer'] ?? '') ?: '____________________');
        $field($c, 250, $y, 'SIGNATURE', '____________________');
        $field($c, 430, $y, 'DATE', $isFinal ? $today : '__________', 100);
        $footer($c, $pw);

        // ---- comment sheet (A4 landscape), may span pages
        $pw = 842;
        $ph = 595;
        $M = 40;
        $first = true;
        $newSheet = function () use (&$c, &$y, &$first, $addPage, $pw, $ph, $M, $isFinal, $P, $T) {
            $c = $addPage($pw, $ph, $first ? 'sheet' : '', 'Comment sheet');
            $first = false;
            $y = $ph - $M;
            $c->text('COMMENT SHEET (SUBMITTAL)', $M, $y - 4, 16, self::INK, true);
            $tag = $isFinal ? 'REVIEWED BY ENGINEER' : 'AUTO-GENERATED DRAFT - FOR ENGINEER REVIEW';
            $c->text($tag, $pw - $M - Canvas::width($tag, 9, true), $y, 9, self::RED, true);
            $y -= 26;
            foreach ([['Project', "{$P['engineerRef']} {$P['name']}, {$P['clientRef']}"], ['Submittal No.', "{$P['submittalNo']}    Rev. {$P['revision']}"], ['Title', $P['title']]] as [$k, $v]) {
                $c->text("$k:", $M, $y, 9, self::INK, true);
                $c->text($T($v), $M + 80, $y, 9, self::INK);
                $y -= 14;
            }
            $y -= 8;
            $c->rect($M, $y - 18, $pw - 2 * $M, 18, ['fill' => self::SHADE, 'border' => self::INK, 'width' => 0.8]);
            $c->text('NO.', $M + 8, $y - 13, 9, self::INK, true);
            $c->text("ENGINEER'S COMMENTS", $M + 40, $y - 13, 9, self::INK, true);
            $c->text('SPEC REF.', $M + 470, $y - 13, 9, self::INK, true);
            $c->text("CONTRACTOR'S RESPONSE", $M + 570, $y - 13, 9, self::INK, true);
            $y -= 18;
        };
        $newSheet();
        foreach ($sheet as $s) {
            $lines = Canvas::wrap($T($s['text']), 9, 420);
            $h = max(count($lines) * 12 + 10, 26);
            if ($y - $h < 90) {
                $newSheet();
            }
            $c->rect($M, $y - $h, $pw - 2 * $M, $h, ['border' => self::INK, 'width' => 0.6]);
            foreach ([$M + 32, $M + 462, $M + 562] as $x) {
                $c->line($x, $y, $x, $y - $h, self::INK, 0.6);
            }
            $c->text((string) $s['no'], $M + 12, $y - 15, 9, self::INK, true);
            foreach ($lines as $i => $l) {
                $c->text($l, $M + 40, $y - 15 - $i * 12, 9, self::INK);
            }
            foreach (Canvas::wrap(self::ascii(($s['clause'] ?? '') ?: 'Engineer'), 8, 90) as $i => $l) {
                $c->text($l, $M + 468, $y - 15 - $i * 11, 8, self::GREY);
            }
            if (($s['status'] ?? '') === 'unclear') {
                $c->text('clarify', $M + 468, $y - $h + 5, 7, self::AMBER, true);
            }
            $y -= $h;
        }
        $y -= 24;
        if ($y < 80) {
            $newSheet();
        }
        $c->text($isFinal ? 'ACTION:' : 'SUGGESTED ACTION:', $M, $y, 10, self::INK, true);
        $c->text(strtoupper($decision), $M + 110, $y, 10, self::RED, true);
        $c->text(! empty($ov['engineer']) ? 'Name: ' . $T($ov['engineer']) . "     Signature: ____________________     Date: $today" : 'Name: ____________________     Signature: ____________________     Date: ____________', $M, $y - 30, 9, self::INK);
        $c->text('Draft generated automatically from the submittal and PART F. The engineer reviews, edits and signs; nothing is issued without human approval.', $M, 30, 7.5, self::GREY);
        if ($hide) {
            $c->text($withheld, $pw - $M - Canvas::width($withheld, 7.5, true), 30, 7.5, self::GREY, true);
        }

        // ---- client's technical control comments on linked submittals
        foreach ($cfg['linkedSubmittals'] ?? [] as $l) {
            $c = $addPage($pw, $ph, 'client', 'Client technical control comments');
            $c->text('TECHNICAL CONTROL COMMENTS (CLIENT)', 40, $ph - 52, 16, self::INK, true);
            $c->text('Linked submittal - carried into this review', 40, $ph - 68, 9, self::GREY);
            $y = $ph - 100;
            $field($c, 46, $y, 'TRANSMITTAL REF.', $l['ref'], 220);
            $field($c, 300, $y, 'SUBJECT', $l['subject'], 220);
            $field($c, 560, $y, 'STATUS', $l['status'], 200);
            $y -= 44;
            $field($c, 46, $y, 'REVIEWED BY', implode(' - ', array_filter([$l['reviewedBy'] ?? null, $l['reviewer'] ?? null])), 220);
            $field($c, 300, $y, 'DATE', $l['reviewedAt'] ?? '');
            $field($c, 560, $y, 'OPEN COMMENTS', "{$l['openComments']} - contractor " . (! empty($l['contractorResponded']) ? 'responded' : 'has NOT responded'), 220);
            $y -= 50;
            $c->rect(40, $y - 16, $pw - 80, 16, ['fill' => self::SHADE, 'border' => self::INK, 'width' => 0.6]);
            $c->text('NO.', 46, $y - 12, 9, self::INK, true);
            $c->text("CLIENT'S COMMENT", 76, $y - 12, 9, self::INK, true);
            $c->text("CONTRACTOR'S RESPONSE", 600, $y - 12, 9, self::INK, true);
            $y -= 16;
            $rows = $l['comments'] ?? [];
            if (! $rows) {
                for ($i = 1; $i <= (int) $l['openComments']; $i++) {
                    $rows[] = "Client comment $i - see attached client document";
                }
            }
            foreach (array_slice($rows, 0, 18) as $i => $txt) {
                $c->rect(40, $y - 20, $pw - 80, 20, ['border' => self::GREY, 'width' => 0.5]);
                $c->text((string) ($i + 1), 48, $y - 14, 9, self::INK, true);
                $c->text(mb_substr($T($txt), 0, 95), 76, $y - 14, 9, self::INK);
                $c->text(! empty($l['contractorResponded']) ? 'Responded' : 'NOT REPLIED', 600, $y - 14, 9, ! empty($l['contractorResponded']) ? self::GREY : self::RED, true);
                $y -= 20;
            }
            $footer($c, $pw);
        }

        // ---- the submittal: review stamp, redaction, marks
        $commentFor = function (string $ruleId) use ($sheet) {
            foreach ($sheet as $s) {
                if (($s['rule'] ?? null) === $ruleId) {
                    return $s['no'];
                }
            }

            return '-';
        };
        $marks = [];
        foreach ($results as $x) {
            if ($x['status'] === 'pass' || empty($x['evidence']['page'])) {
                continue;
            }
            $rule = current(array_filter($cfg['rules'], fn ($q) => $q['id'] === $x['rule']));
            $no = $commentFor($x['rule']);
            $label = $x['status'] === 'fail'
                ? (! empty($rule['markup']) ? "{$rule['markup']} - refer Comment $no" : "NOT ACCEPTED - refer Comment $no")
                : "CLARIFY - refer Comment $no";
            $marks[$x['evidence']['page']][] = ['bbox' => $x['evidence']['bbox'] ?? null, 'label' => self::ascii($label), 'color' => $x['status'] === 'fail' ? self::RED : self::AMBER, 'rule' => $rule['label']];
        }
        $pagesRec = $this->ex['pages'];
        $redDir = Store::dir('redact');
        $redacted = ['text' => 0, 'images' => 0];
        foreach ($srcPages as $i => $pg) {
            $no = $i + 1;
            $mb = $pg['mediabox'];
            $width = $mb[2] - $mb[0];
            $height = $mb[3] - $mb[1];
            $c = new Canvas;
            if ($hide) {
                foreach ($pagesRec[$i]['redact'] ?? [] as [$x0, $y0, $x1, $y1]) {
                    $c->rect($x0, $y0, $x1 - $x0, $y1 - $y0, ['fill' => self::BLACK]);
                }
                $redacted['text'] += $pagesRec[$i]['removed']['text'] ?? 0;
                $redacted['images'] += $pagesRec[$i]['removed']['images'] ?? 0;
            }
            if ($i === 0) {
                $bx = $mb[0] + $width - 250;
                $by = $mb[1] + $height - 110;
                $c->rect($bx, $by, 220, 80, ['border' => self::RED, 'width' => 2, 'fill' => self::WHITE]);
                $c->text($isFinal ? 'REVIEWED' : 'REVIEWED - DRAFT', $bx + 12, $by + 58, 13, self::RED, true);
                $c->text(($isFinal ? 'Action' : 'Suggested') . ": $decision", $bx + 12, $by + 40, 10, self::RED, true);
                $c->text(count($sheet) . ' comments - see Comment Sheet', $bx + 12, $by + 24, 9, self::RED);
                $c->text($today, $bx + 12, $by + 9, 9, self::RED);
            }
            $noteY = $mb[1] + $height - 58;
            foreach ($marks[$no] ?? [] as $m) {
                if ($m['bbox']) {
                    [$x0, $y0, $x1, $y1] = $m['bbox'];
                    $c->rect($x0 - 3, $y0 - 3, $x1 - $x0 + 6, $y1 - $y0 + 6, ['border' => $m['color'], 'width' => 1.8]);
                    $tw = Canvas::width($m['label'], 8.5, true);
                    $lx = min($x0 - 3, $mb[0] + $width - $tw - 16);
                    $ly = $y1 + 6;
                    $c->rect($lx, $ly - 3, $tw + 8, 13, ['fill' => self::WHITE, 'border' => $m['color'], 'width' => 0.8]);
                    $c->text($m['label'], $lx + 4, $ly, 8.5, $m['color'], true);
                } else {
                    $t = "{$m['rule']}: {$m['label']}";
                    $c->rect($mb[0] + 60, $noteY - 6, Canvas::width($t, 10, true) + 16, 20, ['border' => $m['color'], 'width' => 1.5, 'fill' => self::WHITE]);
                    $c->text($t, $mb[0] + 68, $noteY, 10, $m['color'], true);
                    $noteY -= 26;
                }
            }
            $overlay = $c->ops();
            $cleanFile = "$redDir/p$no.bin";
            $clean = $hide && is_file($cleanFile) ? gzuncompress((string) file_get_contents($cleanFile)) : null;
            if ($overlay === '' && $clean === null) {
                continue; // page unchanged
            }
            $d = $pg['dict']->copy();
            if ($clean !== null) {
                $d->set('Contents', $w->stream("q\n" . $clean . "\nQ\n" . $overlay));
            } else {
                $cv = $d->get('Contents');
                $refs = match (true) {
                    is_array($cv) => $cv,
                    $cv instanceof Ref && is_array($arr = $r->resolve($cv)) => $arr, // a reference to an array of streams
                    $cv instanceof Ref => [$cv],
                    default => [],
                };
                $d->set('Contents', array_merge([$w->stream("q\n")], $refs, [$w->stream("\nQ\n" . $overlay)]));
            }
            // make our two fonts available on the page
            $res = $pg['resources'] ? $pg['resources']->copy() : new Dict;
            $fd = $r->dict($res->get('Font'));
            $fd = $fd ? $fd->copy() : new Dict;
            foreach ($fonts as $k => $ref) {
                $fd->set($k, $ref);
            }
            $res->set('Font', $fd);
            $d->set('Resources', $res);
            $w->replace($pg['ref'], $d);
        }
        // form XObjects cleaned during extraction
        if ($hide) {
            foreach (glob("$redDir/f*.bin") ?: [] as $f) {
                $num = (int) substr(basename($f, '.bin'), 1);
                $xo = $r->get($num);
                if (! $xo instanceof Stream) {
                    continue;
                }
                $dict = $xo->dict->copy()->remove('Filter', 'DecodeParms', 'Length')->set('Filter', new Name('FlateDecode'));
                $w->replace(new Ref($num), new Stream($dict, gzcompress(gzuncompress((string) file_get_contents($f)), 6)));
            }
        }

        // generated pages go in front: new page-tree root [generated..., old root]
        $kids = [];
        foreach ($generated as $g) {
            $content = $w->stream($g['c']->ops());
            $kids[] = $w->add(new Dict(['Type' => new Name('Page'), 'Parent' => $newRoot, 'MediaBox' => [0, 0, $g['w'], $g['h']],
                'Resources' => new Dict(['Font' => new Dict($fonts)]), 'Contents' => $content]));
        }
        $cat = $r->root()->copy();
        $oldRoot = $cat->get('Pages');
        if ($oldRoot instanceof Ref) {
            $old = $r->dict($oldRoot)->copy()->set('Parent', $newRoot);
            $w->replace($oldRoot, $old);
            $kids[] = $oldRoot;
        }
        $rootDict->e = ['Type' => new Name('Pages'), 'Kids' => $kids, 'Count' => count($generated) + count($srcPages)];
        $cat->set('Pages', $newRoot);
        if ($hide) {
            $cat->remove('Metadata', 'Outlines', 'Names', 'StructTreeRoot', 'MarkInfo', 'AcroForm', 'PageLabels', 'OpenAction', 'Dests', 'SpiderInfo');
            $info = $w->add(new Dict(['Producer' => new \App\Pdf\Str('Submittal Review Assistant')]));
        } else {
            $cat->remove('PageLabels', 'OpenAction'); // page numbers shift by the generated pages
            $info = $w->add(new Dict(['Title' => new \App\Pdf\Str($T("{$P['submittalNo']} Rev {$P['revision']} - reviewed")), 'Producer' => new \App\Pdf\Str('Submittal Review Assistant')]));
        }
        $catRef = $w->add($cat);

        $outDir = Store::dir('out');
        $pdfName = preg_replace('/[^A-Za-z0-9._-]+/', '-', self::ascii($this->mask($P['submittalNo']))) . "-Rev{$P['revision']}-" . ($isFinal ? 'REVIEWED' : 'REVIEWED-DRAFT') . '.pdf';
        $w->write("$outDir/$pdfName.tmp", $catRef, $info);
        rename("$outDir/$pdfName.tmp", "$outDir/$pdfName");
        // keep only the current output (an unredacted copy must not linger when details are hidden)
        foreach (glob("$outDir/*.pdf") ?: [] as $f) {
            if (basename($f) !== $pdfName) {
                @unlink($f);
            }
        }

        return [
            'pdfName' => $pdfName, 'sheetPages' => count($generated), 'issued' => $issued, 'redacted' => $redacted,
            'marks' => array_map(fn ($list) => array_map(fn ($m) => ['label' => $m['label'], 'rule' => $m['rule'], 'fail' => $m['color'] === self::RED], $list), $marks),
        ];
    }
}
