<?php

namespace App\Pdf;

/**
 * Interprets a page's content stream.
 *  - extraction: collects text items (string, rendering matrix, width) and image boxes, like pdf.js textContent
 *  - redaction:  removes the text-showing and image operators that fall inside given boxes, from the page and
 *                from the form XObjects it draws (so the hidden text is gone from the file, not just covered)
 */
final class ContentWalker
{
    /** @var list<array{s:string,t:array,w:float}> */
    public array $items = [];

    /** @var list<array{0:float,1:float,2:float,3:float}> */
    public array $images = [];

    public int $removedText = 0;

    public int $removedImages = 0;

    /** @var array<int,string> form XObject number => cleaned content (redaction mode) */
    public array $forms = [];

    /** @var array<string,Font> */
    private array $fonts = [];

    /** @var array<int,bool> */
    private array $visiting = [];

    /**
     * @param  list<array{0:float,1:float,2:float,3:float}>|null  $boxes  redaction boxes (null = extraction only)
     * @param  null|callable(int):?string  $formSource  previously cleaned content of a form, if any
     */
    public function __construct(private Reader $r, private ?array $boxes = null, private $formSource = null) {}

    /** Walk a page. In redaction mode returns the cleaned content (or null when nothing changed). */
    public function page(Dict $page, ?Dict $resources): ?string
    {
        $content = $this->r->pageContent($page);

        $out = $this->run($content, $resources, [1, 0, 0, 1, 0, 0], 0);
        $this->flush();

        return $out;
    }

    /** @var array{s:string,t:array,w:float,end:array,u:array,em:float,font:int}|null item being built */
    private ?array $cur = null;

    private function flush(): void
    {
        if ($this->cur !== null) {
            $this->items[] = ['s' => $this->cur['s'], 't' => $this->cur['t'], 'w' => $this->cur['w']];
            $this->cur = null;
        }
    }

    /**
     * Add one visible glyph, merging it into the current item the way pdf.js builds textContent:
     * same font and line, gap <= 0.102 em joins, gap <= 0.6 em joins with a space, otherwise a new item.
     */
    private function glyph(string $txt, array $trm, float $adv, Font $font): void
    {
        $em = hypot($trm[2], $trm[3]) ?: 1.0;
        $len = hypot($trm[0], $trm[1]) ?: 1.0;
        $u = [$trm[0] / $len, $trm[1] / $len];
        $p = [$trm[4], $trm[5]];
        $c = &$this->cur;
        if ($c !== null) {
            $same = $c['font'] === spl_object_id($font) && abs($c['em'] - $em) <= 0.01 * $em
                && abs($c['u'][0] - $u[0]) < 1e-3 && abs($c['u'][1] - $u[1]) < 1e-3;
            $dx = $p[0] - $c['end'][0];
            $dy = $p[1] - $c['end'][1];
            $along = ($dx * $u[0] + $dy * $u[1]) / $em;
            $perp = ($dy * $u[0] - $dx * $u[1]) / $em;
            if (! $same || abs($perp) > 0.5 || $along < -0.2 || $along > 0.6) {
                $this->flush();
            } else {
                if ($along > 0.102 && ! str_ends_with($c['s'], ' ')) {
                    $c['s'] .= ' ';
                }
                $c['s'] .= $txt;
                $c['end'] = [$p[0] + $adv * $u[0], $p[1] + $adv * $u[1]];
                $c['w'] = ($c['end'][0] - $c['t'][4]) * $u[0] + ($c['end'][1] - $c['t'][5]) * $u[1];

                return;
            }
        }
        $this->cur = ['s' => $txt, 't' => $trm, 'w' => $adv, 'end' => [$p[0] + $adv * $u[0], $p[1] + $adv * $u[1]], 'u' => $u, 'em' => $em, 'font' => spl_object_id($font)];
    }

    public static function mul(array $m, array $n): array
    {
        return [
            $m[0] * $n[0] + $m[1] * $n[2], $m[0] * $n[1] + $m[1] * $n[3],
            $m[2] * $n[0] + $m[3] * $n[2], $m[2] * $n[1] + $m[3] * $n[3],
            $m[4] * $n[0] + $m[5] * $n[2] + $n[4], $m[4] * $n[1] + $m[5] * $n[3] + $n[5],
        ];
    }

    private static function apply(array $m, float $x, float $y): array
    {
        return [$m[0] * $x + $m[2] * $y + $m[4], $m[1] * $x + $m[3] * $y + $m[5]];
    }

    private static function unitBox(array $ctm): array
    {
        $pts = [self::apply($ctm, 0, 0), self::apply($ctm, 1, 0), self::apply($ctm, 0, 1), self::apply($ctm, 1, 1)];
        $xs = array_column($pts, 0);
        $ys = array_column($pts, 1);

        return [min($xs), min($ys), max($xs), max($ys)];
    }

    private function inside(array $p, float $pad = 2): bool
    {
        foreach ($this->boxes as [$x0, $y0, $x1, $y1]) {
            if ($p[0] >= $x0 - $pad && $p[0] <= $x1 + $pad && $p[1] >= $y0 - $pad && $p[1] <= $y1 + $pad) {
                return true;
            }
        }

        return false;
    }

    private function overlaps(array $a): bool
    {
        foreach ($this->boxes as $b) {
            if ($a[0] < $b[2] && $a[2] > $b[0] && $a[1] < $b[3] && $a[3] > $b[1]) {
                return true;
            }
        }

        return false;
    }

    private function font(?Dict $res, string $name): Font
    {
        $fd = $this->r->dict($res?->get('Font'));
        $ref = $fd?->get($name);
        $key = $ref instanceof Ref ? 'r' . $ref->num : 'n' . spl_object_id($fd ?? $this) . $name;
        if (! isset($this->fonts[$key])) {
            try {
                $this->fonts[$key] = new Font($this->r, $this->r->dict($ref));
            } catch (\Throwable) {
                $this->fonts[$key] = new Font($this->r, null);
            }
        }

        return $this->fonts[$key];
    }

    /** Parse operands with a lightweight reader (faster than the generic parser for big drawings). */
    private function operand(Lexer $lx, array $tok): mixed
    {
        [$type, $text] = $tok;

        return match ($type) {
            Lexer::T_WORD => is_numeric($text) ? +$text : Lexer::num($text),
            Lexer::T_NAME => new Name(Lexer::name($text)),
            Lexer::T_STR => new Str(Lexer::unescape(substr($text, 1, -1))),
            Lexer::T_HEX => new Str((string) hex2bin(($h = preg_replace('/[^0-9A-Fa-f]/', '', substr($text, 1, -1))) . (strlen($h) % 2 ? '0' : ''))),
            Lexer::T_ARR => $text === '[' ? $this->array($lx) : null,
            Lexer::T_DICT => $text === '<<' ? (function () use ($lx, $tok) { $lx->pos = $tok[2]; return $lx->value(); })() : null,
            default => null,
        };
    }

    private function array(Lexer $lx): array
    {
        $a = [];
        while (($t = $lx->next()) !== null) {
            if ($t[0] === Lexer::T_ARR && $t[1] === ']') {
                break;
            }
            if ($t[0] === Lexer::T_WORD && ! is_numeric($t[1]) && ! preg_match('/^[+-]?[\d.]/', $t[1])) {
                continue; // stray keyword inside an array
            }
            $a[] = $this->operand($lx, $t);
        }

        return $a;
    }

    private function run(string $data, ?Dict $res, array $ctm0, int $depth): ?string
    {
        $redact = $this->boxes !== null;
        $lx = new Lexer($data);
        $cuts = [];
        $gs = ['ctm' => $ctm0, 'font' => null, 'fs' => 0.0, 'tc' => 0.0, 'tw' => 0.0, 'th' => 1.0, 'tl' => 0.0, 'ts' => 0.0];
        $stack = [];
        $tm = $tlm = [1, 0, 0, 1, 0, 0];
        $ops = [];
        $opStart = null;
        $xobjs = $this->r->dict($res?->get('XObject'));

        // Show text: advances the text matrix glyph by glyph and returns the glyph origins (page space).
        $show = function (array $parts) use (&$gs, &$tm, $redact) {
            $font = $gs['font'];
            $fs = $gs['fs'];
            $th = $gs['th'];
            $hit = false;
            foreach ($parts as $p) {
                if (is_int($p) || is_float($p)) {
                    $tm = self::mul([1, 0, 0, 1, -$p / 1000 * $fs * $th, 0], $tm);

                    continue;
                }
                if (! $p instanceof Str || ! $font) {
                    continue;
                }
                foreach ($font->glyphs($p->bytes) as [$txt, $w, $isSpace]) {
                    $adv = ($w * $fs + $gs['tc'] + ($isSpace ? $gs['tw'] : 0)) * $th;
                    if ($txt !== '' && trim($txt, " \u{a0}\t") !== '') {
                        $trm = self::mul(self::mul([$fs * $th, 0, 0, $fs, 0, $gs['ts']], $tm), $gs['ctm']);
                        if ($redact) {
                            $hit = $hit || $this->inside([$trm[4], $trm[5]]);
                        } else {
                            $m = self::mul($tm, $gs['ctm']);
                            $this->glyph($txt, $trm, $adv * hypot($m[0], $m[1]), $font);
                        }
                    }
                    $tm = self::mul([1, 0, 0, 1, $adv, 0], $tm);
                }
            }

            return $hit;
        };

        while (($t = $lx->next()) !== null) {
            if ($t[0] !== Lexer::T_WORD || is_numeric($t[1]) || preg_match('/^[+-]?\.?\d/', $t[1])) {
                $opStart ??= $t[2];
                $ops[] = $this->operand($lx, $t);

                continue;
            }
            $op = $t[1];
            $s0 = $opStart ?? $t[2];
            $a = $ops;
            $ops = [];
            $opStart = null;
            switch ($op) {
                case 'q':
                    $stack[] = $gs;
                    break;
                case 'Q':
                    if ($stack) {
                        $gs = array_pop($stack);
                    }
                    break;
                case 'cm':
                    if (count($a) === 6) {
                        $gs['ctm'] = self::mul(array_map('floatval', $a), $gs['ctm']);
                    }
                    break;
                case 'BT':
                    $tm = $tlm = [1, 0, 0, 1, 0, 0];
                    break;
                case 'Tf':
                    if (count($a) >= 2 && $a[0] instanceof Name) {
                        // (a font change ends the current item inside glyph())
                        $gs['font'] = $this->font($res, $a[0]->v);
                        $gs['fs'] = (float) $a[1];
                    }
                    break;
                case 'Tc': $gs['tc'] = (float) ($a[0] ?? 0); break;
                case 'Tw': $gs['tw'] = (float) ($a[0] ?? 0); break;
                case 'Tz': $gs['th'] = (float) ($a[0] ?? 100) / 100; break;
                case 'TL': $gs['tl'] = (float) ($a[0] ?? 0); break;
                case 'Ts': $gs['ts'] = (float) ($a[0] ?? 0); break;
                case 'Tm':
                    if (count($a) === 6) {
                        $tm = $tlm = array_map('floatval', $a);
                    }
                    break;
                case 'Td':
                    $tm = $tlm = self::mul([1, 0, 0, 1, (float) ($a[0] ?? 0), (float) ($a[1] ?? 0)], $tlm);
                    break;
                case 'TD':
                    $gs['tl'] = -(float) ($a[1] ?? 0);
                    $tm = $tlm = self::mul([1, 0, 0, 1, (float) ($a[0] ?? 0), (float) ($a[1] ?? 0)], $tlm);
                    break;
                case 'T*':
                    $tm = $tlm = self::mul([1, 0, 0, 1, 0, -$gs['tl']], $tlm);
                    break;
                case 'Tj':
                case 'TJ':
                case "'":
                case '"':
                    if ($op === "'" || $op === '"') {
                        if ($op === '"' && count($a) === 3) {
                            $gs['tw'] = (float) $a[0];
                            $gs['tc'] = (float) $a[1];
                        }
                        $tm = $tlm = self::mul([1, 0, 0, 1, 0, -$gs['tl']], $tlm);
                    }
                    $arg = end($a);
                    $parts = is_array($arg) ? $arg : [$arg];
                    if ($show($parts) && $redact) {
                        $cuts[] = [$s0, $t[3]];
                        $this->removedText++;
                    }
                    break;
                case 'BI':
                    $biStart = $t[2];
                    // key/value pairs up to ID
                    while (($k = $lx->next()) !== null && ! ($k[0] === Lexer::T_WORD && $k[1] === 'ID')) {
                    }
                    if ($k === null) {
                        break 2;
                    }
                    $p = $k[3] + 1;
                    if (preg_match('/[\x00\t\n\f\r ]EI(?=[\x00\t\n\f\r ]|$)/', $data, $mm, PREG_OFFSET_CAPTURE, $p)) {
                        $eiEnd = $mm[0][1] + 3;
                    } else {
                        $eiEnd = strlen($data);
                    }
                    $lx->pos = $eiEnd;
                    $bb = self::unitBox($gs['ctm']);
                    if ($redact) {
                        if ($this->overlaps($bb)) {
                            $cuts[] = [$biStart, $eiEnd];
                            $this->removedImages++;
                        }
                    } elseif ($bb[2] - $bb[0] > 12 && $bb[3] - $bb[1] > 12) {
                        $this->images[] = $bb;
                    }
                    break;
                case 'Do':
                    $name = $a[0] ?? null;
                    if (! $name instanceof Name || ! $xobjs) {
                        break;
                    }
                    $ref = $xobjs->get($name->v);
                    $xo = $this->r->resolve($ref);
                    if (! $xo instanceof Stream) {
                        break;
                    }
                    $sub = $xo->dict->name('Subtype');
                    if ($sub === 'Image') {
                        $bb = self::unitBox($gs['ctm']);
                        if ($redact) {
                            if ($this->overlaps($bb)) {
                                $cuts[] = [$s0, $t[3]];
                                $this->removedImages++;
                            }
                        } elseif ($bb[2] - $bb[0] > 12 && $bb[3] - $bb[1] > 12) {
                            $this->images[] = $bb;
                        }
                    } elseif ($sub === 'Form' && $depth < 12) {
                        $num = $ref instanceof Ref ? $ref->num : null;
                        if ($num !== null && isset($this->visiting[$num])) {
                            break;
                        }
                        $m = $this->r->resolve($xo->dict->get('Matrix'));
                        $m = is_array($m) && count($m) === 6 ? array_map(fn ($x) => (float) $this->r->resolve($x), $m) : [1, 0, 0, 1, 0, 0];
                        $fres = $this->r->dict($xo->dict->get('Resources')) ?? $res;
                        $src = null;
                        if ($redact && $num !== null) {
                            $src = $this->forms[$num] ?? ($this->formSource ? ($this->formSource)($num) : null);
                        }
                        $src ??= $this->r->decode($xo);
                        if ($num !== null) {
                            $this->visiting[$num] = true;
                        }
                        $this->flush();
                        $clean = $this->run($src, $fres, self::mul($m, $gs['ctm']), $depth + 1);
                        if ($num !== null) {
                            unset($this->visiting[$num]);
                        }
                        if ($redact && $clean !== null && $num !== null) {
                            $this->forms[$num] = $clean;
                        }
                    }
                    break;
            }
        }

        if (! $redact || ! $cuts) {
            return null;
        }
        // blank the cut ranges with spaces so every other byte offset stays valid
        foreach ($cuts as [$s, $e]) {
            $data = substr_replace($data, str_repeat(' ', $e - $s), $s, $e - $s);
        }

        return $data;
    }
}
