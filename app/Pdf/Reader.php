<?php

namespace App\Pdf;

use RuntimeException;

/**
 * Reads a PDF file: cross-reference tables or streams (with incremental updates), object streams
 * and the common filters. Falls back to scanning the file when the xref is damaged.
 */
final class Reader
{
    /** @var array<int,array{t:int,off?:int,gen?:int,stm?:int,idx?:int}> */
    public array $xref = [];

    public Dict $trailer;

    /** @var array<int,mixed> parsed non-stream objects */
    private array $cache = [];

    /** @var array<int,array<int,int>> object stream number => [index => offset in decoded data] */
    private array $objStm = [];

    /** @var array<int,string> */
    private array $objStmData = [];

    private bool $repaired = false;

    public function __construct(public readonly string $data)
    {
        if (! str_starts_with(ltrim(substr($data, 0, 1024)), '%PDF') && ! str_contains(substr($data, 0, 1024), '%PDF')) {
            throw new RuntimeException('Not a PDF file');
        }
        $this->trailer = new Dict;
        try {
            $this->readXref();
        } catch (\Throwable) {
            $this->repair();
        }
        if (! $this->trailer->get('Root')) {
            $this->repair();
        }
        if ($this->trailer->get('Encrypt')) {
            throw new RuntimeException('Encrypted (password-protected) PDFs are not supported — please save an unprotected copy.');
        }
    }

    public static function open(string $file): self
    {
        $d = file_get_contents($file);
        if ($d === false) {
            throw new RuntimeException("Cannot read $file");
        }

        return new self($d);
    }

    // ---------------------------------------------------------------- xref

    private function readXref(): void
    {
        $tail = substr($this->data, -2048);
        if (! preg_match_all('/startxref\s+(\d+)/', $tail, $m)) {
            throw new RuntimeException('startxref not found');
        }
        $off = (int) end($m[1]);
        $seen = [];
        $first = true;
        while ($off > 0 && ! isset($seen[$off])) {
            $seen[$off] = true;
            $tr = $this->readXrefSection($off);
            if ($first) {
                $this->trailer = $tr;
                $first = false;
            } else {
                foreach ($tr->e as $k => $v) { // keys from older trailers fill gaps only
                    if (! $this->trailer->has($k)) {
                        $this->trailer->e[$k] = $v;
                    }
                }
            }
            if (is_int($stm = $tr->get('XRefStm'))) {
                $this->readXrefSection($stm);
            }
            $off = is_int($p = $tr->get('Prev')) ? $p : 0;
        }
    }

    private function readXrefSection(int $off): Dict
    {
        $lx = new Lexer($this->data, $off);
        $t = $lx->next();
        if ($t === null) {
            throw new RuntimeException('bad xref offset');
        }
        if ($t[1] === 'xref') {
            // classic table
            while (true) {
                $save = $lx->pos;
                $a = $lx->next();
                if ($a === null) {
                    throw new RuntimeException('truncated xref');
                }
                if ($a[1] === 'trailer') {
                    $tr = $lx->value();

                    return $tr instanceof Dict ? $tr : new Dict;
                }
                $b = $lx->next();
                if (! ctype_digit($a[1]) || $b === null || ! ctype_digit($b[1])) {
                    throw new RuntimeException('bad xref subsection');
                }
                $start = (int) $a[1];
                $count = (int) $b[1];
                for ($i = 0; $i < $count; $i++) {
                    $o = $lx->next();
                    $g = $lx->next();
                    $f = $lx->next();
                    if ($o === null || $g === null || $f === null) {
                        throw new RuntimeException('truncated xref');
                    }
                    $num = $start + $i;
                    if (isset($this->xref[$num])) {
                        continue;
                    }
                    $this->xref[$num] = $f[1] === 'n' ? ['t' => 1, 'off' => (int) $o[1], 'gen' => (int) $g[1]] : ['t' => 0];
                }
                unset($save);
            }
        }
        // xref stream: "n g obj << /Type /XRef ... >> stream"
        $lx->pos = $off;
        [, $obj] = $this->parseObjectAt($off);
        if (! $obj instanceof Stream || $obj->dict->name('Type') !== 'XRef') {
            throw new RuntimeException('xref stream expected');
        }
        $d = $obj->dict;
        $w = $d->get('W');
        $size = (int) $d->get('Size', 0);
        $index = $d->get('Index') ?: [0, $size];
        $bytes = $this->decode($obj);
        $rowLen = array_sum($w);
        $p = 0;
        for ($k = 0; $k + 1 < count($index); $k += 2) {
            for ($i = 0; $i < $index[$k + 1]; $i++) {
                if ($p + $rowLen > strlen($bytes)) {
                    break 2;
                }
                $f = [];
                foreach ($w as $j => $len) {
                    $v = 0;
                    for ($b = 0; $b < $len; $b++) {
                        $v = ($v << 8) | ord($bytes[$p++]);
                    }
                    $f[$j] = $len === 0 ? ($j === 0 ? 1 : 0) : $v;
                }
                $num = $index[$k] + $i;
                if (isset($this->xref[$num])) {
                    continue;
                }
                $this->xref[$num] = match ($f[0]) {
                    1 => ['t' => 1, 'off' => $f[1], 'gen' => $f[2]],
                    2 => ['t' => 2, 'stm' => $f[1], 'idx' => $f[2]],
                    default => ['t' => 0],
                };
            }
        }

        return $d;
    }

    /** Rebuild the xref by scanning for "n g obj" (last definition wins). */
    private function repair(): void
    {
        if ($this->repaired) {
            return;
        }
        $this->repaired = true;
        $this->xref = [];
        $this->cache = [];
        preg_match_all('/(?<![0-9])(\d+)[\x00\t\n\f\r ]+(\d+)[\x00\t\n\f\r ]+obj\b/', $this->data, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as $i => $whole) {
            $this->xref[(int) $m[1][$i][0]] = ['t' => 1, 'off' => $whole[1], 'gen' => (int) $m[2][$i][0]];
        }
        $root = null;
        // objects inside object streams, and a catalog to use as Root
        foreach (array_keys($this->xref) as $num) {
            try {
                $o = $this->get($num);
            } catch (\Throwable) {
                continue;
            }
            if ($o instanceof Stream && $o->dict->name('Type') === 'ObjStm') {
                foreach ($this->objStmIndex($num) as $idx => $_) {
                    $inner = $this->objStmNumbers[$num][$idx] ?? null;
                    if ($inner !== null && ! isset($this->xref[$inner])) {
                        $this->xref[$inner] = ['t' => 2, 'stm' => $num, 'idx' => $idx];
                    }
                }
            } elseif ($o instanceof Stream && $o->dict->name('Type') === 'XRef' && $o->dict->get('Root')) {
                $root ??= $o->dict->get('Root');
            }
        }
        if (preg_match_all('/trailer\s*(<<)/', $this->data, $tm, PREG_OFFSET_CAPTURE)) {
            $lx = new Lexer($this->data, end($tm[1])[1]);
            $tr = $lx->value();
            if ($tr instanceof Dict) {
                $this->trailer = $tr;
            }
        }
        $root ??= $this->trailer->get('Root');
        if (! $root || ! ($this->get($root) instanceof Dict)) {
            foreach (array_keys($this->xref) as $num) {
                try {
                    $o = $this->get($num);
                } catch (\Throwable) {
                    continue;
                }
                if ($o instanceof Dict && $o->name('Type') === 'Catalog') {
                    $root = new Ref($num, 0);
                }
            }
        }
        if (! $root) {
            throw new RuntimeException('Damaged PDF: no catalog found');
        }
        $this->trailer->set('Root', $root);
    }

    // ---------------------------------------------------------------- objects

    /** @var array<int,array<int,int>> */
    private array $objStmNumbers = [];

    /**
     * @return array{0:?Ref,1:mixed}
     */
    private function parseObjectAt(int $off): array
    {
        $lx = new Lexer($this->data, $off);
        $a = $lx->next();
        $b = $lx->next();
        $c = $lx->next();
        if ($a === null || $b === null || $c === null || $c[1] !== 'obj') {
            throw new RuntimeException("No object at offset $off");
        }
        $val = $lx->value();
        if ($val instanceof Dict) {
            $save = $lx->pos;
            $t = $lx->next();
            if ($t !== null && $t[1] === 'stream') {
                $p = $t[3];
                if (($this->data[$p] ?? '') === "\r") {
                    $p++;
                }
                if (($this->data[$p] ?? '') === "\n") {
                    $p++;
                }
                $len = $val->get('Length');
                if ($len instanceof Ref) {
                    try {
                        $len = $this->get($len);
                    } catch (\Throwable) {
                        $len = null;
                    }
                }
                $raw = null;
                if (is_int($len) && $len >= 0 && preg_match('/\G[\x00\t\n\f\r ]*endstream/', $this->data, $mm, 0, $p + $len)) {
                    $raw = substr($this->data, $p, $len);
                }
                if ($raw === null) {
                    $e = strpos($this->data, 'endstream', $p);
                    $e = $e === false ? strlen($this->data) : $e;
                    $raw = rtrim(substr($this->data, $p, $e - $p), "\r\n");
                }

                return [new Ref((int) $a[1], (int) $b[1]), new Stream($val, $raw)];
            }
            $lx->pos = $save;
        }

        return [new Ref((int) $a[1], (int) $b[1]), $val];
    }

    /** @return array<int,int> index => offset (relative to First) */
    private function objStmIndex(int $stm): array
    {
        if (isset($this->objStm[$stm])) {
            return $this->objStm[$stm];
        }
        $s = $this->get($stm);
        if (! $s instanceof Stream) {
            return $this->objStm[$stm] = [];
        }
        $data = $this->decode($s);
        $n = (int) $s->dict->get('N', 0);
        $first = (int) $s->dict->get('First', 0);
        $lx = new Lexer($data);
        $idx = [];
        $nums = [];
        for ($i = 0; $i < $n; $i++) {
            $a = $lx->next();
            $b = $lx->next();
            if ($a === null || $b === null) {
                break;
            }
            $nums[$i] = (int) $a[1];
            $idx[$i] = $first + (int) $b[1];
        }
        $this->objStmData[$stm] = $data;
        $this->objStmNumbers[$stm] = $nums;

        return $this->objStm[$stm] = $idx;
    }

    /** Get an object by number or reference (streams are not cached to save memory). */
    public function get(int|Ref $ref): mixed
    {
        $num = $ref instanceof Ref ? $ref->num : $ref;
        if (array_key_exists($num, $this->cache)) {
            return $this->cache[$num];
        }
        $e = $this->xref[$num] ?? null;
        if ($e === null || $e['t'] === 0) {
            return null;
        }
        if ($e['t'] === 1) {
            try {
                [, $v] = $this->parseObjectAt($e['off']);
            } catch (\Throwable $ex) {
                if (! $this->repaired) {
                    $this->repair();

                    return $this->get($num);
                }

                return null;
            }
        } else {
            $idx = $this->objStmIndex($e['stm']);
            if (! isset($idx[$e['idx']])) {
                return null;
            }
            $lx = new Lexer($this->objStmData[$e['stm']], $idx[$e['idx']]);
            $v = $lx->value();
        }
        if (! $v instanceof Stream) {
            $this->cache[$num] = $v;
        }

        return $v;
    }

    /** Follow a reference (if it is one). */
    public function resolve(mixed $v): mixed
    {
        $guard = 0;
        while ($v instanceof Ref && $guard++ < 32) {
            $v = $this->get($v);
        }

        return $v;
    }

    public function dict(mixed $v): ?Dict
    {
        $v = $this->resolve($v);
        if ($v instanceof Stream) {
            return $v->dict;
        }

        return $v instanceof Dict ? $v : null;
    }

    public function root(): Dict
    {
        $r = $this->dict($this->trailer->get('Root'));
        if (! $r) {
            throw new RuntimeException('PDF has no catalog');
        }

        return $r;
    }

    // ---------------------------------------------------------------- pages

    /**
     * Flatten the page tree.
     *
     * @return list<array{ref:Ref,dict:Dict,resources:?Dict,mediabox:array,rotate:int}>
     */
    public function pages(): array
    {
        $out = [];
        $seen = [];
        $walk = function ($ref, array $inh) use (&$walk, &$out, &$seen) {
            $key = $ref instanceof Ref ? $ref->num : null;
            if ($key !== null && isset($seen[$key])) {
                return;
            }
            if ($key !== null) {
                $seen[$key] = true;
            }
            $node = $this->dict($ref);
            if (! $node) {
                return;
            }
            foreach (['Resources', 'MediaBox', 'CropBox', 'Rotate'] as $k) {
                if ($node->has($k)) {
                    $inh[$k] = $node->get($k);
                }
            }
            $kids = $this->resolve($node->get('Kids'));
            if ($node->name('Type') === 'Pages' || (is_array($kids) && $node->name('Type') !== 'Page')) {
                foreach ((array) $kids as $k) {
                    $walk($k, $inh);
                }

                return;
            }
            $mb = $this->resolve($inh['MediaBox'] ?? null);
            $mb = is_array($mb) && count($mb) === 4 ? array_map(fn ($x) => (float) $this->resolve($x), $mb) : [0, 0, 612, 792];
            $out[] = [
                'ref' => $ref instanceof Ref ? $ref : new Ref(0),
                'dict' => $node,
                'resources' => $this->dict($inh['Resources'] ?? null),
                'mediabox' => $mb,
                'rotate' => (int) $this->resolve($inh['Rotate'] ?? 0),
            ];
        };
        $walk($this->root()->get('Pages'), []);

        return $out;
    }

    /** Decoded bytes of all content streams of a page, joined. */
    public function pageContent(Dict $page): string
    {
        $c = $this->resolve($page->get('Contents'));
        $parts = [];
        if ($c instanceof Stream) {
            $parts[] = $this->decode($c);
        } elseif (is_array($c)) {
            foreach ($c as $r) {
                $s = $this->resolve($r);
                if ($s instanceof Stream) {
                    $parts[] = $this->decode($s);
                }
            }
        }

        return implode("\n", $parts);
    }

    // ---------------------------------------------------------------- filters

    public function decode(Stream $s): string
    {
        $data = $s->raw;
        $filters = $this->resolve($s->dict->get('Filter'));
        $parms = $this->resolve($s->dict->get('DecodeParms') ?? $s->dict->get('DP'));
        $filters = $filters instanceof Name ? [$filters] : (is_array($filters) ? $filters : []);
        $parms = is_array($parms) ? $parms : [$parms];
        foreach ($filters as $i => $f) {
            $f = $this->resolve($f);
            $p = $this->dict($parms[$i] ?? null);
            $name = $f instanceof Name ? $f->v : '';
            $data = match ($name) {
                'FlateDecode', 'Fl' => self::predict(self::inflate($data), $p),
                'LZWDecode', 'LZW' => self::predict(self::lzw($data, (int) ($p?->get('EarlyChange', 1) ?? 1)), $p),
                'ASCIIHexDecode', 'AHx' => (string) hex2bin(self::evenHex(preg_replace('/[^0-9A-Fa-f]/', '', explode('>', $data)[0]))),
                'ASCII85Decode', 'A85' => self::ascii85($data),
                'RunLengthDecode', 'RL' => self::runLength($data),
                default => $data, // image codecs (DCT, JPX, CCITT, JBIG2) are kept as-is
            };
            if (! in_array($name, ['FlateDecode', 'Fl', 'LZWDecode', 'LZW', 'ASCIIHexDecode', 'AHx', 'ASCII85Decode', 'A85', 'RunLengthDecode', 'RL'], true)) {
                break;
            }
        }

        return $data;
    }

    private static function evenHex(string $h): string
    {
        return strlen($h) % 2 ? $h . '0' : $h;
    }

    public static function inflate(string $d): string
    {
        if ($d === '') {
            return '';
        }
        $r = @gzuncompress($d);
        if ($r !== false) {
            return $r;
        }
        $r = @gzinflate(substr($d, 2));
        if ($r !== false) {
            return $r;
        }
        // truncated or corrupt stream: keep whatever inflates
        $ctx = @inflate_init(ZLIB_ENCODING_ANY);
        $out = '';
        if ($ctx) {
            foreach (str_split($d, 1024) as $chunk) {
                $part = @inflate_add($ctx, $chunk, ZLIB_SYNC_FLUSH);
                if ($part === false) {
                    break;
                }
                $out .= $part;
            }
        }

        return $out;
    }

    private static function predict(string $d, ?Dict $p): string
    {
        $pred = (int) ($p?->get('Predictor', 1) ?? 1);
        if ($pred < 2) {
            return $d;
        }
        $colors = (int) ($p->get('Colors', 1) ?: 1);
        $bpc = (int) ($p->get('BitsPerComponent', 8) ?: 8);
        $cols = (int) ($p->get('Columns', 1) ?: 1);
        $bpp = max(1, intdiv($colors * $bpc + 7, 8));
        $row = intdiv($colors * $bpc * $cols + 7, 8);
        if ($pred === 2) {
            if ($bpc !== 8) {
                return $d;
            }
            $out = '';
            foreach (str_split($d, $row) as $line) {
                $l = array_values(unpack('C*', $line));
                for ($i = $bpp; $i < count($l); $i++) {
                    $l[$i] = ($l[$i] + $l[$i - $bpp]) & 0xFF;
                }
                $out .= pack('C*', ...$l);
            }

            return $out;
        }
        $out = '';
        $prev = array_fill(0, $row, 0);
        $n = strlen($d);
        for ($pos = 0; $pos < $n; $pos += $row + 1) {
            $type = ord($d[$pos]);
            $line = substr($d, $pos + 1, $row);
            if (strlen($line) < $row) {
                $line = str_pad($line, $row, "\0");
            }
            $cur = array_values(unpack('C*', $line));
            for ($i = 0; $i < $row; $i++) {
                $a = $i >= $bpp ? $cur[$i - $bpp] : 0;
                $b = $prev[$i];
                $c = $i >= $bpp ? $prev[$i - $bpp] : 0;
                $cur[$i] = match ($type) {
                    1 => $cur[$i] + $a,
                    2 => $cur[$i] + $b,
                    3 => $cur[$i] + (($a + $b) >> 1),
                    4 => $cur[$i] + self::paeth($a, $b, $c),
                    default => $cur[$i],
                } & 0xFF;
            }
            $out .= pack('C*', ...$cur);
            $prev = $cur;
        }

        return $out;
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        return $pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c);
    }

    private static function ascii85(string $d): string
    {
        $d = preg_replace('/\s+/', '', $d);
        if (str_starts_with($d, '<~')) {
            $d = substr($d, 2);
        }
        $d = explode('~>', $d)[0];
        $out = '';
        $tuple = [];
        $len = strlen($d);
        for ($i = 0; $i < $len; $i++) {
            $c = $d[$i];
            if ($c === 'z' && ! $tuple) {
                $out .= "\0\0\0\0";

                continue;
            }
            $tuple[] = ord($c) - 33;
            if (count($tuple) === 5) {
                $v = 0;
                foreach ($tuple as $t) {
                    $v = $v * 85 + $t;
                }
                $out .= pack('N', $v & 0xFFFFFFFF);
                $tuple = [];
            }
        }
        if ($tuple) {
            $n = count($tuple);
            $tuple = array_pad($tuple, 5, 84);
            $v = 0;
            foreach ($tuple as $t) {
                $v = $v * 85 + $t;
            }
            $out .= substr(pack('N', $v & 0xFFFFFFFF), 0, $n - 1);
        }

        return $out;
    }

    private static function runLength(string $d): string
    {
        $out = '';
        $n = strlen($d);
        for ($i = 0; $i < $n;) {
            $l = ord($d[$i++]);
            if ($l === 128) {
                break;
            }
            if ($l < 128) {
                $out .= substr($d, $i, $l + 1);
                $i += $l + 1;
            } else {
                $out .= str_repeat($d[$i] ?? '', 257 - $l);
                $i++;
            }
        }

        return $out;
    }

    private static function lzw(string $d, int $early): string
    {
        $dict = [];
        for ($i = 0; $i < 256; $i++) {
            $dict[$i] = chr($i);
        }
        $next = 258;
        $bits = 9;
        $out = '';
        $prev = null;
        $buf = 0;
        $nbuf = 0;
        $n = strlen($d);
        for ($i = 0; $i < $n; $i++) {
            $buf = ($buf << 8) | ord($d[$i]);
            $nbuf += 8;
            while ($nbuf >= $bits) {
                $code = ($buf >> ($nbuf - $bits)) & ((1 << $bits) - 1);
                $nbuf -= $bits;
                $buf &= (1 << $nbuf) - 1;
                if ($code === 256) {
                    $dict = array_slice($dict, 0, 256, true);
                    $next = 258;
                    $bits = 9;
                    $prev = null;

                    continue;
                }
                if ($code === 257) {
                    return $out;
                }
                if ($prev === null) {
                    $entry = $dict[$code] ?? '';
                } else {
                    $entry = $dict[$code] ?? ($prev . $prev[0]);
                    $dict[$next++] = $prev . $entry[0];
                }
                $out .= $entry;
                $prev = $entry;
                if ($next + $early >= (1 << $bits) && $bits < 12) {
                    $bits++;
                }
            }
        }

        return $out;
    }
}
