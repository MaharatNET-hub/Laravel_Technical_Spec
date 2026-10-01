<?php

namespace App\Pdf;

/**
 * Builds a content stream with the two standard fonts (Helvetica / Helvetica-Bold, WinAnsi),
 * mirroring the small drawing API the demo needs: rectangles, lines and text.
 */
final class Canvas
{
    public const FONT = 'SRHv';

    public const BOLD = 'SRHvB';

    private string $ops = '';

    /** @var array<string,array<int,int>> font => [WinAnsi code => width] */
    private static array $w = [];

    public static function fontDicts(): array
    {
        $f = fn ($base) => new Dict(['Type' => new Name('Font'), 'Subtype' => new Name('Type1'), 'BaseFont' => new Name($base), 'Encoding' => new Name('WinAnsiEncoding')]);

        return [self::FONT => $f('Helvetica'), self::BOLD => $f('Helvetica-Bold')];
    }

    /** UTF-8 -> WinAnsi bytes (characters outside it become "?"). */
    public static function winAnsi(string $s): string
    {
        static $rev = null;
        if ($rev === null) {
            $rev = array_flip(FontData::WIN_ANSI);
        }
        $out = '';
        foreach (mb_str_split($s, 1, 'UTF-8') as $ch) {
            $cp = mb_ord($ch, 'UTF-8');
            $out .= isset($rev[$cp]) ? chr($rev[$cp]) : ($cp === 0x2212 ? '-' : '?');
        }

        return $out;
    }

    private static function widths(bool $bold): array
    {
        $k = $bold ? 'Helvetica-Bold' : 'Helvetica';
        if (! isset(self::$w[$k])) {
            $byCp = [];
            foreach (FontData::GLYPHS as $name => $cp) {
                $byCp[$cp][] = $name;
            }
            $m = FontData::WIDTHS[$k];
            $t = [];
            foreach (FontData::WIN_ANSI as $code => $cp) {
                foreach ($byCp[$cp] ?? [] as $name) {
                    if (isset($m[$name])) {
                        $t[$code] = $m[$name];
                        break;
                    }
                }
            }
            $t[45] = $m['hyphen'];
            self::$w[$k] = $t;
        }

        return self::$w[$k];
    }

    public static function width(string $text, float $size, bool $bold = false): float
    {
        $w = self::widths($bold);
        $sum = 0;
        foreach (str_split(self::winAnsi($text)) as $c) {
            $sum += $w[ord($c)] ?? 556;
        }

        return $sum * $size / 1000;
    }

    /** Word-wrap to a width. */
    public static function wrap(string $text, float $size, float $width, bool $bold = false): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/ /', $text) as $word) {
            $t = $line === '' ? $word : "$line $word";
            if ($line !== '' && self::width($t, $size, $bold) > $width) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $t;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

    private static function n(float $v): string
    {
        $s = rtrim(rtrim(sprintf('%.3F', $v), '0'), '.');

        return $s === '-0' || $s === '' ? '0' : $s;
    }

    private static function color(array $c): string
    {
        return implode(' ', array_map(fn ($x) => self::n($x), $c));
    }

    /** @param array{border?:array,fill?:array,width?:float} $o */
    public function rect(float $x, float $y, float $w, float $h, array $o = []): static
    {
        $r = self::n($x) . ' ' . self::n($y) . ' ' . self::n($w) . ' ' . self::n($h) . ' re ';
        $this->ops .= 'q ';
        if (isset($o['fill'])) {
            $this->ops .= self::color($o['fill']) . ' rg ';
        }
        if (isset($o['border'])) {
            $this->ops .= self::color($o['border']) . ' RG ' . self::n($o['width'] ?? 1) . ' w ';
        }
        $paint = isset($o['fill'], $o['border']) ? 'B' : (isset($o['fill']) ? 'f' : 'S');
        $this->ops .= $r . $paint . " Q\n";

        return $this;
    }

    public function line(float $x0, float $y0, float $x1, float $y1, array $color, float $width = 1): static
    {
        $this->ops .= 'q ' . self::color($color) . ' RG ' . self::n($width) . ' w ' . self::n($x0) . ' ' . self::n($y0) . ' m ' . self::n($x1) . ' ' . self::n($y1) . " l S Q\n";

        return $this;
    }

    public function text(string $s, float $x, float $y, float $size, array $color, bool $bold = false): static
    {
        $b = strtr(self::winAnsi($s), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '\\r', "\n" => '\\n']);
        $this->ops .= 'q BT ' . self::color($color) . ' rg /' . ($bold ? self::BOLD : self::FONT) . ' ' . self::n($size) . ' Tf ' . self::n($x) . ' ' . self::n($y) . " Td ($b) Tj ET Q\n";

        return $this;
    }

    public function ops(): string
    {
        return $this->ops;
    }
}
