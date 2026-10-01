<?php

namespace App\Pdf;

/**
 * Writes a new PDF from a Reader plus changes. Only objects reachable from the new catalog are
 * written (a full rewrite, not an incremental update), so replaced content — e.g. redacted text —
 * does not survive anywhere in the output file.
 */
final class Writer
{
    /** @var array<int,mixed> old object number => replacement value */
    private array $replace = [];

    /** @var array<int,mixed> new object number => value */
    private array $new = [];

    /** @var array<int,int> old number => output number */
    private array $map = [];

    /** @var list<array{0:string,1:int}> queue of [kind, number] to write */
    private array $queue = [];

    private int $next = 1;

    /** Keys dropped from every dictionary (used to strip metadata when client details are hidden). */
    public array $stripKeys = [];

    public function __construct(private Reader $r) {}

    /** Register a new object; returns a reference usable in other values. */
    public function add(mixed $value): NewRef
    {
        $n = $this->next++;
        $this->new[$n] = $value;
        $this->queue[] = ['n', $n];

        return new NewRef($n);
    }

    /** A new FlateDecode stream from uncompressed bytes. */
    public function stream(string $bytes, array $dict = []): NewRef
    {
        return $this->add(new Stream(new Dict($dict + ['Filter' => new Name('FlateDecode')]), gzcompress($bytes, 6)));
    }

    public function replace(Ref $old, mixed $value): void
    {
        $this->replace[$old->num] = $value;
    }

    private function outNum(Ref $ref): ?int
    {
        if (! isset($this->replace[$ref->num]) && ! isset($this->r->xref[$ref->num])) {
            return null;
        }
        if (! isset($this->map[$ref->num])) {
            $this->map[$ref->num] = $this->next++;
            $this->queue[] = ['o', $ref->num];
        }

        return $this->map[$ref->num];
    }

    public function serialize(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            if (! is_finite($v)) {
                return '0';
            }
            $s = rtrim(rtrim(sprintf('%.5F', $v), '0'), '.');

            return $s === '-0' || $s === '' ? '0' : $s;
        }
        if ($v instanceof Name) {
            return '/' . preg_replace_callback('/[^!-~]|[#%()<>\[\]{}\/]/', fn ($m) => sprintf('#%02X', ord($m[0])), $v->v);
        }
        if ($v instanceof Str) {
            return '<' . bin2hex($v->bytes) . '>';
        }
        if ($v instanceof NewRef) {
            return $v->num . ' 0 R';
        }
        if ($v instanceof Ref) {
            $n = $this->outNum($v);

            return $n === null ? 'null' : $n . ' 0 R';
        }
        if (is_array($v)) {
            return '[' . implode(' ', array_map(fn ($x) => $this->serialize($x), $v)) . ']';
        }
        if ($v instanceof Dict) {
            $out = '<<';
            foreach ($v->e as $k => $x) {
                if ($this->stripKeys && in_array($k, $this->stripKeys, true)) {
                    continue;
                }
                $out .= $this->serialize(new Name((string) $k)) . ' ' . $this->serialize($x);
            }

            return $out . '>>';
        }

        return 'null';
    }

    /**
     * Write the file. $root and $info are the trailer entries (normally new objects).
     */
    public function write(string $file, mixed $root, mixed $info = null): void
    {
        $fh = fopen($file, 'wb');
        if (! $fh) {
            throw new \RuntimeException("Cannot write $file");
        }
        $pos = 0;
        $put = function (string $s) use ($fh, &$pos) {
            fwrite($fh, $s);
            $pos += strlen($s);
        };
        $put("%PDF-1.7\n%\xE2\xE3\xCF\xD3\n");
        $offsets = [];
        // serialising the trailer values first puts their objects in the queue
        $rootS = $this->serialize($root);
        $infoS = $info !== null ? $this->serialize($info) : null;
        for ($i = 0; $i < count($this->queue); $i++) {
            [$kind, $n] = $this->queue[$i];
            if ($kind === 'n') {
                $num = $n;
                $val = $this->new[$n];
                unset($this->new[$n]);
            } else {
                $num = $this->map[$n];
                $val = array_key_exists($n, $this->replace) ? $this->replace[$n] : $this->r->get($n);
            }
            $offsets[$num] = $pos;
            if ($val instanceof Stream) {
                $d = $val->dict->copy();
                $d->remove('Length');
                if ($this->stripKeys) {
                    $d->remove(...$this->stripKeys);
                }
                $d->set('Length', strlen($val->raw));
                $put("$num 0 obj\n" . $this->serialize($d) . "\nstream\n");
                $put($val->raw);
                $put("\nendstream\nendobj\n");
            } else {
                $put("$num 0 obj\n" . $this->serialize($val) . "\nendobj\n");
            }
        }
        $size = $this->next;
        $xref = $pos;
        $put("xref\n0 $size\n0000000000 65535 f \n");
        for ($n = 1; $n < $size; $n++) {
            $put(isset($offsets[$n]) ? sprintf("%010d 00000 n \n", $offsets[$n]) : "0000000000 65535 f \n");
        }
        $id = bin2hex(random_bytes(16));
        $put("trailer\n<</Size $size /Root $rootS" . ($infoS ? " /Info $infoS" : '') . " /ID [<$id><$id>]>>\nstartxref\n$xref\n%%EOF\n");
        fclose($fh);
    }
}
