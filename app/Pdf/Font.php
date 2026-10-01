<?php

namespace App\Pdf;

/**
 * Turns the bytes of a text-showing operator into glyphs (Unicode text + advance width), for
 * simple fonts (Type1/TrueType/Type3) and composite fonts (Type0 / CID).
 */
final class Font
{
    /** @var array<int,string> code => Unicode text */
    private array $toUnicode = [];

    /** @var array<int,float> code => width in glyph space (1/1000 em unless Type3) */
    private array $widths = [];

    private float $defaultWidth = 500;

    /** @var list<array{0:int,1:int,2:int}> codespace ranges [bytes, lo, hi] */
    private array $codespace = [];

    private bool $composite = false;

    private float $scale = 0.001; // glyph space -> text space

    /** @var array<int,string> simple-font code => Unicode from the encoding */
    private array $encoding = [];

    public function __construct(Reader $r, ?Dict $d)
    {
        if (! $d) {
            $this->encoding = self::winAnsi();
            $this->defaultWidth = 500;

            return;
        }
        $subtype = $d->name('Subtype');
        $this->composite = $subtype === 'Type0';
        if ($subtype === 'Type3') {
            $m = $r->resolve($d->get('FontMatrix'));
            if (is_array($m) && isset($m[0])) {
                $this->scale = (float) $r->resolve($m[0]);
            }
        }

        if ($this->composite) {
            $desc = $r->resolve($d->get('DescendantFonts'));
            $cid = $r->dict(is_array($desc) ? ($desc[0] ?? null) : $desc);
            $this->defaultWidth = (float) ($r->resolve($cid?->get('DW')) ?? 1000);
            $w = $r->resolve($cid?->get('W'));
            if (is_array($w)) {
                $this->parseW($r, $w);
            }
            $enc = $r->resolve($d->get('Encoding'));
            $this->codespace = [[2, 0, 0xFFFF]];
            if ($enc instanceof Stream) {
                $cs = $this->parseCodespace($r->decode($enc));
                if ($cs) {
                    $this->codespace = $cs;
                }
            }
        } else {
            $this->codespace = [[1, 0, 255]];
            $base = $d->name('BaseFont') ?? '';
            $base = preg_replace('/^[A-Z]{6}\+/', '', $base); // subset prefix
            $this->encoding = $this->simpleEncoding($r, $d, $base);
            $first = (int) ($r->resolve($d->get('FirstChar')) ?? 0);
            $ws = $r->resolve($d->get('Widths'));
            $fd = $r->dict($d->get('FontDescriptor'));
            $this->defaultWidth = (float) ($r->resolve($fd?->get('MissingWidth')) ?? 0) ?: 500;
            if (is_array($ws) && $ws) {
                foreach ($ws as $i => $w) {
                    $this->widths[$first + $i] = (float) $r->resolve($w);
                }
            } else {
                $std = self::standardMetrics($base);
                if ($std) {
                    $names = array_map(fn ($n) => [$n], $this->diffNames) + self::codeNames($this->encoding);
                    foreach ($names as $code => $cands) {
                        foreach ($cands as $glyph) {
                            if (isset($std[$glyph])) {
                                $this->widths[$code] = $std[$glyph];
                                break;
                            }
                        }
                    }
                    $this->defaultWidth = $std['space'] ?? 500;
                } elseif (str_contains(strtolower($base), 'courier')) {
                    $this->defaultWidth = 600;
                }
            }
        }

        $tu = $r->resolve($d->get('ToUnicode'));
        if ($tu instanceof Stream) {
            $this->parseCMap($r->decode($tu));
        }
    }

    /**
     * Split string bytes into glyphs.
     *
     * @return list<array{0:string,1:float,2:bool}> [text, width in text space per unit font size, is single-byte space]
     */
    public function glyphs(string $bytes): array
    {
        $out = [];
        $n = strlen($bytes);
        for ($i = 0; $i < $n;) {
            [$code, $len] = $this->readCode($bytes, $i);
            $i += $len;
            $text = $this->toUnicode[$code] ?? ($this->composite ? '' : ($this->encoding[$code] ?? ''));
            if ($text === '' && $this->composite && $code < 0x80 && $code >= 0x20 && ! $this->toUnicode) {
                $text = chr($code); // CID fonts without ToUnicode: best effort
            }
            $w = ($this->widths[$code] ?? $this->defaultWidth) * $this->scale;
            $out[] = [$text, $w, $len === 1 && $code === 32];
        }

        return $out;
    }

    private function readCode(string $b, int $i): array
    {
        if (! $this->composite) {
            return [ord($b[$i]), 1];
        }
        $code = 0;
        for ($len = 1; $len <= 4 && $i + $len <= strlen($b); $len++) {
            $code = ($code << 8) | ord($b[$i + $len - 1]);
            foreach ($this->codespace as [$bytes, $lo, $hi]) {
                if ($bytes === $len && $code >= $lo && $code <= $hi) {
                    return [$code, $len];
                }
            }
        }
        // no range matched: fall back to 2 bytes
        $len = min(2, strlen($b) - $i);

        return [$len === 2 ? (ord($b[$i]) << 8) | ord($b[$i + 1]) : ord($b[$i]), $len];
    }

    private function parseW(Reader $r, array $w): void
    {
        $n = count($w);
        for ($i = 0; $i < $n;) {
            $first = (int) $r->resolve($w[$i]);
            $next = $r->resolve($w[$i + 1] ?? null);
            if (is_array($next)) {
                foreach ($next as $k => $v) {
                    $this->widths[$first + $k] = (float) $r->resolve($v);
                }
                $i += 2;
            } else {
                $last = (int) $next;
                $v = (float) $r->resolve($w[$i + 2] ?? 0);
                for ($c = $first; $c <= $last && $c - $first < 65536; $c++) {
                    $this->widths[$c] = $v;
                }
                $i += 3;
            }
        }
    }

    private function parseCodespace(string $cmap): array
    {
        $out = [];
        if (preg_match_all('/begincodespacerange(.*?)endcodespacerange/s', $cmap, $m)) {
            foreach ($m[1] as $block) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $p, PREG_SET_ORDER);
                foreach ($p as $x) {
                    $out[] = [intdiv(strlen($x[1]) + 1, 2), hexdec($x[1]), hexdec($x[2])];
                }
            }
        }

        return $out;
    }

    private function parseCMap(string $cmap): void
    {
        if ($this->composite) {
            $cs = $this->parseCodespace($cmap);
            if ($cs && $this->codespace === [[2, 0, 0xFFFF]]) {
                // keep Identity 2-byte unless the ToUnicode clearly uses another code length
                $lens = array_unique(array_column($cs, 0));
                if ($lens !== [2]) {
                    $this->codespace = $cs;
                }
            }
        }
        $u = fn (string $hex) => self::utf16(hex2bin(strlen($hex) % 2 ? '0' . $hex : $hex));
        if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $m)) {
            foreach ($m[1] as $block) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*(?:<([0-9A-Fa-f]*)>|\/(\S+))/', $block, $p, PREG_SET_ORDER);
                foreach ($p as $x) {
                    $this->toUnicode[hexdec($x[1])] = isset($x[3]) && $x[3] !== '' ? $this->glyphText($x[3]) : $u($x[2]);
                }
            }
        }
        if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $m)) {
            foreach ($m[1] as $block) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<[0-9A-Fa-f]*>|\[[^\]]*\])/', $block, $p, PREG_SET_ORDER);
                foreach ($p as $x) {
                    $lo = hexdec($x[1]);
                    $hi = hexdec($x[2]);
                    if ($hi - $lo > 65535) {
                        continue;
                    }
                    if ($x[3][0] === '[') {
                        preg_match_all('/<([0-9A-Fa-f]*)>/', $x[3], $arr);
                        foreach ($arr[1] as $k => $h) {
                            $this->toUnicode[$lo + $k] = $u($h);
                        }

                        continue;
                    }
                    $start = substr($x[3], 1, -1);
                    $bin = hex2bin(strlen($start) % 2 ? '0' . $start : $start);
                    for ($c = $lo; $c <= $hi; $c++) {
                        $this->toUnicode[$c] = self::utf16($bin);
                        // increment the last byte
                        $last = strlen($bin) - 1;
                        if ($last >= 0) {
                            $bin[$last] = chr((ord($bin[$last]) + 1) & 0xFF);
                        }
                    }
                }
            }
        }
    }

    private static function utf16(string $b): string
    {
        if ($b === '') {
            return '';
        }
        if (strlen($b) === 1) {
            return mb_chr(ord($b), 'UTF-8') ?: '';
        }
        $s = @mb_convert_encoding($b, 'UTF-8', 'UTF-16BE');

        return is_string($s) ? $s : '';
    }

    private function glyphText(string $name): string
    {
        if (isset(FontData::GLYPHS[$name])) {
            return mb_chr(FontData::GLYPHS[$name], 'UTF-8');
        }
        if (preg_match('/^uni([0-9A-Fa-f]{4})/', $name, $m) || preg_match('/^u([0-9A-Fa-f]{4,6})$/', $name, $m)) {
            return mb_chr(hexdec($m[1]), 'UTF-8') ?: '';
        }
        if (preg_match('/^(?:g|cid|c)(\d+)$/', $name)) {
            return '';
        }
        $base = explode('.', $name)[0];
        if ($base !== $name) {
            return $this->glyphText($base);
        }

        return strlen($name) === 1 ? $name : '';
    }

    /** @return array<int,string> */
    private static function winAnsi(): array
    {
        static $t = null;
        if ($t === null) {
            $t = [];
            foreach (FontData::WIN_ANSI as $code => $cp) {
                $t[$code] = mb_chr($cp, 'UTF-8');
            }
        }

        return $t;
    }

    private function simpleEncoding(Reader $r, Dict $d, string $base): array
    {
        $map = self::winAnsi();
        $enc = $r->resolve($d->get('Encoding'));
        if ($enc instanceof Dict) {
            $diff = $r->resolve($enc->get('Differences'));
            if (is_array($diff)) {
                $code = 0;
                foreach ($diff as $x) {
                    $x = $r->resolve($x);
                    if (is_int($x) || is_float($x)) {
                        $code = (int) $x;
                    } elseif ($x instanceof Name) {
                        $map[$code] = $this->glyphText($x->v);
                        $this->diffNames[$code] = $x->v;
                        $code++;
                    }
                }
            }
        }

        return $map;
    }

    /** @var array<int,string> */
    private array $diffNames = [];

    /** @return array<int,list<string>> code => candidate glyph names (for standard-font widths) */
    private static function codeNames(array $encoding): array
    {
        static $byCp = null;
        if ($byCp === null) {
            $byCp = [];
            foreach (FontData::GLYPHS as $name => $cp) {
                $byCp[$cp][] = $name;
            }
            $byCp[0x2D][] = 'hyphen';
        }
        $out = [];
        foreach ($encoding as $code => $text) {
            $cp = $text === '' ? null : mb_ord($text, 'UTF-8');
            if ($cp !== null && isset($byCp[$cp])) {
                $out[$code] = $byCp[$cp];
            }
        }

        return $out;
    }

    private static function standardMetrics(string $base): ?array
    {
        $b = strtolower(str_replace([' ', '-', ','], '', $base));
        $bold = str_contains($b, 'bold');
        $ital = str_contains($b, 'italic') || str_contains($b, 'oblique');
        if (str_contains($b, 'times')) {
            $k = 'Times-' . ($bold && $ital ? 'BoldItalic' : ($bold ? 'Bold' : ($ital ? 'Italic' : 'Roman')));
        } elseif (str_contains($b, 'helvetica') || str_contains($b, 'arial')) {
            $k = 'Helvetica' . ($bold && $ital ? '-BoldOblique' : ($bold ? '-Bold' : ($ital ? '-Oblique' : '')));
        } else {
            return null;
        }

        return FontData::WIDTHS[$k] ?? null;
    }
}
