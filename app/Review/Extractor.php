<?php

namespace App\Review;

use App\Pdf\ContentWalker;
use App\Pdf\Reader;

/**
 * Step 1 — read every page (text + coordinates), classify it, extract panel values and find the
 * text / images to redact. Runs in time-boxed chunks so it fits a shared host's request limit.
 */
final class Extractor
{
    private const PANEL_RE = '/^(EMDB|ESMDB|SMDB|MDB|DB|FDB|LDB|PDB|MCC)-[A-Z0-9-]+$/';

    /** Start a new job for a PDF file. */
    public static function start(string $file, string $name): array
    {
        $r = Reader::open($file); // validates the PDF early
        $total = count($r->pages());
        if ($total === 0) {
            throw new \RuntimeException('The PDF has no pages');
        }
        Store::clearDir('redact');
        Store::delete('pages.json', 'extracted.json', 'overrides.json');
        $job = ['source' => $file, 'name' => $name, 'total' => $total, 'next' => 1, 'started' => microtime(true)];
        Store::write('job.json', $job);
        Store::write('pages.json', []);

        return $job;
    }

    /**
     * Process pages until the time budget is spent.
     *
     * @return array{page:int,total:int,panel:?string,done:bool}
     */
    public static function step(float $budget = 12.0): array
    {
        $t0 = microtime(true);
        $job = Store::read('job.json');
        if (! $job) {
            throw new \RuntimeException('No submittal is being processed');
        }
        // one batch at a time per review (two browsers may drive the same submission)
        $lock = fopen(Store::path('job.lock'), 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            return ['page' => $job['next'] - 1, 'total' => $job['total'], 'panel' => null, 'done' => $job['next'] > $job['total'] && Store::read('extracted.json') !== null, 'busy' => true];
        }
        $job = Store::read('job.json'); // re-read under the lock
        if ($job['next'] > $job['total']) {
            flock($lock, LOCK_UN);

            return ['page' => $job['total'], 'total' => $job['total'], 'panel' => null, 'done' => true];
        }
        $cfg = Store::rules();
        $re = '/' . implode('|', $cfg['disclosure']['redactPatterns']) . '/i';
        $r = Reader::open($job['source']);
        $pages = $r->pages();
        $records = Store::read('pages.json', []);
        $last = null;
        $formDir = Store::dir('redact');
        $formSource = fn (int $num) => is_file("$formDir/f$num.bin") ? gzuncompress((string) file_get_contents("$formDir/f$num.bin")) : null;
        while ($job['next'] <= $job['total']) {
            $i = $job['next'] - 1;
            $pg = $pages[$i];
            $rec = self::page($r, $pg, $job['next'], $re);
            // pre-compute the redacted content (used when client details are hidden)
            if ($rec['redact']) {
                $w = new ContentWalker($r, $rec['redact'], $formSource);
                $clean = $w->page($pg['dict'], $pg['resources']);
                if ($clean !== null) {
                    file_put_contents("$formDir/p{$job['next']}.bin", gzcompress($clean, 6));
                }
                foreach ($w->forms as $num => $bytes) {
                    file_put_contents("$formDir/f$num.bin", gzcompress($bytes, 6));
                }
                $rec['removed'] = ['text' => $w->removedText, 'images' => $w->removedImages];
            }
            $records[] = $rec;
            $last = $rec['panel'] ?? $last;
            $job['next']++;
            if (microtime(true) - $t0 > $budget) {
                break;
            }
        }
        Store::write('pages.json', $records);
        $done = $job['next'] > $job['total'];
        if ($done) {
            self::finish($job, $records);
        }
        Store::write('job.json', $job);
        flock($lock, LOCK_UN);

        return ['page' => $job['next'] - 1, 'total' => $job['total'], 'panel' => $last, 'done' => $done];
    }

    private static function norm(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /** Page-space box of a (possibly rotated) text run. */
    private static function textBox(array $t, float $width): array
    {
        [$a, $b, $c, $d, $e, $f] = $t;
        $h = hypot($c, $d) ?: 9;
        $len = hypot($a, $b) ?: 1;
        $ux = $a / $len;
        $uy = $b / $len;
        $pts = [];
        foreach ([[0, -0.3 * $h], [$width, -0.3 * $h], [0, $h], [$width, $h]] as [$u, $v]) {
            $pts[] = [$e + $u * $ux - $v * $uy, $f + $u * $uy + $v * $ux];
        }
        $xs = array_column($pts, 0);
        $ys = array_column($pts, 1);

        return [min($xs) - 1, min($ys) - 1, max($xs) + 1, max($ys) + 1];
    }

    private static function page(Reader $r, array $pg, int $no, string $re): array
    {
        $w = new ContentWalker($r);
        $w->page($pg['dict'], $pg['resources']);
        $its = [];
        foreach ($w->items as $it) {
            $s = self::norm($it['s']);
            if ($s === '') {
                continue;
            }
            $its[] = ['s' => $s, 'x' => $it['t'][4], 'y' => $it['t'][5], 'w' => $it['w'], 'h' => abs($it['t'][3]) ?: 9];
        }
        $text = implode(' ', array_column($its, 's'));
        $rec = ['page' => $no, 'type' => self::pageType($text), 'panel' => self::panelName($its), 'values' => new \stdClass];
        $v = [];

        if ($rec['type'] === 'COVER') {
            // the declared type can be split into several text runs on one row ("FORM-" "4" "," "TYPE-6," "IP-4" "3")
            $lab = self::find($its, fn ($i) => $i['s'] === 'STANDARD EQUIPMENT TYPE');
            if ($lab) {
                $cands = array_filter($its, fn ($i) => preg_match('/^FORM-|^IP-?\d/', $i['s']) && $i['y'] < $lab['y']);
                usort($cands, fn ($a, $b) => $b['y'] <=> $a['y']);
                $first = $cands[array_key_first($cands)] ?? null;
                if ($first) {
                    $row = array_values(array_filter($its, fn ($i) => abs($i['y'] - $first['y']) < 2 && $i['x'] >= $lab['x'] - 20));
                    usort($row, fn ($a, $b) => $a['x'] <=> $b['x']);
                    $value = preg_replace('/,(?=\S)/', ', ', implode('', array_column($row, 's')));
                    $lastIt = end($row);
                    $v['declared'] = ['value' => $value, 'bbox' => [$row[0]['x'] - 3, $first['y'] - 3, $lastIt['x'] + $lastIt['w'] + 3, $first['y'] + $first['h'] + 2]];
                }
            }
        }

        if ($rec['type'] === 'DATASHEET') {
            $ip = self::pick($its, ['IP-43', 'IP-54', 'IP-65'], 'right', 220);
            $form = self::pick($its, ['FORM-1', 'FORM-2', 'FORM-3B', 'FORM-4, TYPE-6'], 'right', 220);
            $make = self::pick($its, ['EATON', 'EATON (xENERGY)', 'SCHNEIDER', 'ABB', 'SIEMENS'], 'right', 220);
            $bus = self::pick($its, ['COPPER', 'ALUM.'], 'left', 30);
            $tin = self::pick($its, ['TIN PLATED'], 'left', 30);
            // aux wiring sizes: "[X] 1.5 SQ.MM"
            $wires = array_values(array_filter(self::pick($its, ['1.5', '2.5', '4', '6'], 'left', 30, true), function ($o) use ($its) {
                if (! empty($o['sq'])) {
                    return true;
                }
                foreach ($its as $i) {
                    if (str_starts_with($i['s'], 'SQ.MM') && abs($i['y'] - $o['bbox'][1] - 3) <= 4) {
                        return true;
                    }
                }

                return false;
            }));
            $wires = array_map(fn ($o) => ['value' => $o['value'], 'bbox' => $o['bbox']], $wires);
            $icw = self::find($its, fn ($i) => (bool) preg_match('/^Icw\s*=/', $i['s']));
            if ($ip) {
                $v['ip'] = $ip;
            }
            if ($form) {
                $v['form'] = $form;
            }
            if ($make) {
                $v['make'] = $make;
            }
            if ($bus) {
                $v['busbar'] = $bus;
            }
            $v['tinPlated'] = count($tin) > 0;
            if ($wires) {
                $v['auxWire'] = $wires;
            }
            if ($icw) {
                $v['icw'] = ['value' => $icw['s'], 'bbox' => [$icw['x'] - 3, $icw['y'] - 3, $icw['x'] + $icw['w'] + 3, $icw['y'] + $icw['h'] + 2]];
            }
        }

        if ($rec['type'] === 'MATERIAL' || $rec['type'] === 'SLD') {
            $v['hasHeater'] = (bool) preg_match('/HEATER/i', $text);
            $v['hasThermostat'] = (bool) preg_match('/THERMOSTAT|HYGROSTAT/i', $text);
        }

        if ($rec['type'] === 'GA') {
            if (preg_match('/TYPE\s*:\s*(FORM-?\s*\d[^)]*?)(?=\s+[a-z]\)|\s{2}|$)/i', $text, $m)) {
                $v['gaForm'] = ['value' => self::norm($m[1])];
            }
            if (preg_match('/INGRESS PROTECTION\s*:\s*(IP-?\s*\d\d)/i', $text, $m)) {
                $v['gaIp'] = ['value' => preg_replace('/\s/', '', $m[1])];
            }
        }
        $rec['values'] = $v ?: new \stdClass;

        $boxes = [];
        foreach ($w->items as $it) {
            if (trim($it['s']) !== '' && preg_match($re, $it['s'])) {
                $boxes[] = self::textBox($it['t'], $it['w']);
            }
        }
        foreach ($w->images as $b) {
            $boxes[] = $b;
        }
        $rec['redact'] = array_map(fn ($b) => array_map(fn ($x) => round($x, 1), $b), $boxes);

        return $rec;
    }

    private static function find(array $its, callable $f): ?array
    {
        foreach ($its as $i) {
            if ($f($i)) {
                return $i;
            }
        }

        return null;
    }

    private static function pageType(string $text): string
    {
        return match (true) {
            (bool) preg_match('/STANDARD EQUIPMENT TYPE/', $text) => 'COVER',
            (bool) preg_match('/PANEL LIST/', $text) => 'LIST',
            (bool) preg_match('/DATA SHEET/', $text) => 'DATASHEET',
            (bool) preg_match('/MATERIAL LIST|PART LIST|MATERIAL DESC/i', $text) => 'MATERIAL',
            (bool) preg_match('/SCHEMATIC|SINGLE LINE/i', $text) => 'SLD',
            (bool) preg_match('/FRONT VIEW|SIDE VIEW|GENERAL ARRANGEMENT|INGRESS PROTECTION/i', $text) => 'GA',
            default => 'OTHER',
        };
    }

    private static function panelName(array $its): ?string
    {
        $cands = array_values(array_filter($its, fn ($i) => preg_match(self::PANEL_RE, $i['s'])));
        if (! $cands) {
            // a title block value may be joined with its label ("PANEL NAME EMDB-A-1")
            foreach ($its as $i) {
                if (preg_match('/^PANEL NAME:?\s+((?:EMDB|ESMDB|SMDB|MDB|DB|FDB|LDB|PDB|MCC)-[A-Z0-9-]+)$/', $i['s'], $m)) {
                    return $m[1];
                }
            }

            return null;
        }
        $label = self::find($its, fn ($i) => $i['s'] === 'PANEL NAME');
        if (! $label) {
            return $cands[0]['s'];
        }
        usort($cands, fn ($a, $b) => hypot($a['x'] - $label['x'], $a['y'] - $label['y']) <=> hypot($b['x'] - $label['x'], $b['y'] - $label['y']));

        return $cands[0]['s'];
    }

    /** Is there a check-box "X" on the same row as the label? */
    private static function checked(array $its, array $label, string $side, float $maxGap): ?array
    {
        foreach ($its as $x) {
            if ($x['s'] !== 'X' || abs($x['y'] - $label['y']) > 4) {
                continue;
            }
            $ok = $side === 'right'
                ? $x['x'] > $label['x'] + $label['w'] && $x['x'] - ($label['x'] + $label['w']) <= $maxGap
                : $x['x'] < $label['x'] && $label['x'] - ($x['x'] + $x['w']) <= $maxGap;
            if ($ok) {
                return $x;
            }
        }

        return null;
    }

    /**
     * Ticked options. Accepts the label and its "X" as separate runs, or merged into one run
     * ("X 1.5", "IP-43 X", "X 1.5 SQ.MM") — the text extractor joins runs that touch.
     */
    private static function pick(array $its, array $options, string $side, float $maxGap, bool $withUnit = false): array
    {
        $found = [];
        foreach ($options as $opt) {
            $q = preg_quote($opt, '/');
            foreach ($its as $lab) {
                $s = $lab['s'];
                $unit = $withUnit && preg_match("/^(?:X\s+)?{$q}\s+SQ\.MM/", $s);
                $core = $withUnit ? preg_replace('/\s+SQ\.MM.*$/', '', $s) : $s;
                if ($core === $opt) {
                    $x = self::checked($its, $lab, $side, $maxGap);
                    if ($x) {
                        $found[] = ['value' => $opt, 'bbox' => [min($lab['x'], $x['x']) - 3, $lab['y'] - 3, max($lab['x'] + $lab['w'], $x['x'] + $x['w']) + 3, $lab['y'] + $lab['h'] + 2], 'sq' => $unit];
                    }
                } elseif (($side === 'left' && preg_match("/^X\s+{$q}$/", $core)) || ($side === 'right' && preg_match("/^{$q}\s+X$/", $core))) {
                    $found[] = ['value' => $opt, 'bbox' => [$lab['x'] - 3, $lab['y'] - 3, $lab['x'] + $lab['w'] + 3, $lab['y'] + $lab['h'] + 2], 'sq' => $unit];
                }
            }
        }
        if (! $withUnit) {
            $found = array_map(fn ($o) => ['value' => $o['value'], 'bbox' => $o['bbox']], $found);
        }

        return $found;
    }

    /** Group pages into panels (a panel runs from its cover sheet until the next one). */
    private static function finish(array $job, array $pages): void
    {
        $panels = [];
        $anomalies = [];
        $current = null;
        foreach ($pages as $pg) {
            if ($pg['type'] === 'COVER' && $pg['panel']) {
                $current = $pg['panel'];
            }
            if ($pg['type'] === 'LIST' || ($pg['type'] === 'OTHER' && ! $pg['panel'])) {
                $current = null; // section divider

                continue;
            }
            $name = $pg['type'] === 'OTHER' && ! $current ? null : ($pg['panel'] ?: $current);
            if (! $name) {
                continue;
            }
            if ($current && $pg['panel'] && $pg['panel'] !== $current) {
                $anomalies[] = ['page' => $pg['page'], 'section' => $current, 'titleBlock' => $pg['panel'], 'type' => $pg['type']];
            }
            $panels[$name] ??= ['name' => $name, 'kind' => explode('-', $name)[0], 'pages' => [], 'values' => []];
            $P = &$panels[$name];
            $P['pages'][] = ['page' => $pg['page'], 'type' => $pg['type']];
            foreach ((array) $pg['values'] as $k => $val) {
                if (in_array($k, ['hasHeater', 'hasThermostat', 'tinPlated'], true)) {
                    $P['values'][$k] = ($P['values'][$k] ?? false) || $val;
                } elseif (! isset($P['values'][$k])) {
                    $P['values'][$k] = (is_array($val) && array_is_list($val) ? ['list' => $val] : $val) + ['page' => $pg['page']];
                }
            }
            unset($P);
        }
        Store::write('extracted.json', [
            'source' => $job['source'], 'sourceName' => $job['name'], 'pageCount' => $job['total'],
            'pages' => $pages, 'panels' => $panels, 'anomalies' => $anomalies,
            'seconds' => round(microtime(true) - $job['started'], 1),
        ]);
    }
}
