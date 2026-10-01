<?php

namespace App\Pdf;

/**
 * Tokeniser + object parser for PDF syntax. Used both on the file itself and on content streams.
 * One anchored regex per token keeps it fast without building huge token arrays.
 */
final class Lexer
{
    // groups: 1 comment, 2 (literal string), 3 <hex>, 4 << or >>, 5 [ ] { }, 6 /name, 7 regular token, 8 any other byte
    public const RE = '~\G[\x00\t\n\f\r ]*+(?:(%[^\r\n]*+)|(\((?:[^()\\\\]++|\\\\.|(?2))*+\))|(<[0-9A-Fa-f\x00\t\n\f\r ]*+>)|(<<|>>)|([\[\]{}])|(/[^\x00\t\n\f\r ()<>\[\]{}/%]*+)|([^\x00\t\n\f\r ()<>\[\]{}/%]++)|([\s\S]))~s';

    public const T_COMMENT = 1, T_STR = 2, T_HEX = 3, T_DICT = 4, T_ARR = 5, T_NAME = 6, T_WORD = 7, T_OTHER = 8;

    public int $pos;

    public function __construct(public string $data, int $pos = 0)
    {
        $this->pos = $pos;
    }

    /**
     * Next token as [type, text, start, end] or null at end of data. Comments are skipped.
     *
     * @return array{0:int,1:string,2:int,3:int}|null
     */
    public function next(): ?array
    {
        $n = strlen($this->data);
        while ($this->pos < $n) {
            if (! preg_match(self::RE, $this->data, $m, 0, $this->pos)) {
                // an unbalanced "(" defeats the recursive pattern: read the string by hand
                $start = $this->pos + strspn($this->data, "\x00\t\n\f\r ", $this->pos);
                if (($this->data[$start] ?? '') === '(') {
                    $end = $this->scanString($start);
                    $this->pos = $end;

                    return [self::T_STR, substr($this->data, $start, $end - $start), $start, $end];
                }
                $this->pos = $n;

                return null;
            }
            $type = count($m) - 1;
            $text = $m[$type];
            $end = $this->pos + strlen($m[0]);
            $start = $end - strlen($text);
            $this->pos = $end;
            if ($type === self::T_COMMENT) {
                continue;
            }
            if ($text === '' && $type !== self::T_NAME) {
                return null;
            }

            return [$type, $text, $start, $end];
        }

        return null;
    }

    private function scanString(int $i): int
    {
        $n = strlen($this->data);
        $depth = 0;
        for (; $i < $n; $i++) {
            $c = $this->data[$i];
            if ($c === '\\') {
                $i++;
            } elseif ($c === '(') {
                $depth++;
            } elseif ($c === ')' && --$depth === 0) {
                return $i + 1;
            }
        }

        return $n;
    }

    /** Parse one PDF value starting at the current position (handles "n g R"). */
    public function value(?array $tok = null): mixed
    {
        $tok ??= $this->next();
        if ($tok === null) {
            return null;
        }
        [$type, $text] = $tok;
        switch ($type) {
            case self::T_STR:
                return new Str(self::unescape(substr($text, 1, -1)));
            case self::T_HEX:
                $h = preg_replace('/[^0-9A-Fa-f]/', '', substr($text, 1, -1));
                if (strlen($h) % 2) {
                    $h .= '0';
                }

                return new Str((string) hex2bin($h));
            case self::T_NAME:
                return new Name(self::name($text));
            case self::T_DICT:
                if ($text === '>>') {
                    return null;
                }
                $d = new Dict;
                while (($k = $this->next()) !== null) {
                    if ($k[0] === self::T_DICT && $k[1] === '>>') {
                        break;
                    }
                    if ($k[0] !== self::T_NAME) {
                        continue; // malformed: skip stray token
                    }
                    $save = $this->pos;
                    $vt = $this->next();
                    if ($vt !== null && $vt[0] === self::T_DICT && $vt[1] === '>>') {
                        break;
                    }
                    if ($vt !== null && $vt[0] === self::T_NAME) {
                        // value is a name (or the key had no value and this is the next key — rare; treat as value)
                        $d->e[self::name($k[1])] = new Name(self::name($vt[1]));

                        continue;
                    }
                    $this->pos = $save;
                    $d->e[self::name($k[1])] = $this->value();
                }

                return $d;
            case self::T_ARR:
                if ($text !== '[') {
                    return null;
                }
                $a = [];
                while (($t = $this->next()) !== null) {
                    if ($t[0] === self::T_ARR && $t[1] === ']') {
                        break;
                    }
                    $a[] = $this->value($t);
                }

                return $a;
            case self::T_WORD:
                if (is_numeric($text) || preg_match('/^[+-]?(\d+\.?\d*|\.\d+)$/', $text)) {
                    // integer followed by "gen R"?
                    if (ctype_digit($text)) {
                        $save = $this->pos;
                        $g = $this->next();
                        if ($g !== null && $g[0] === self::T_WORD && ctype_digit($g[1])) {
                            $r = $this->next();
                            if ($r !== null && $r[0] === self::T_WORD && $r[1] === 'R') {
                                return new Ref((int) $text, (int) $g[1]);
                            }
                        }
                        $this->pos = $save;

                        return (int) $text;
                    }

                    return self::num($text);
                }

                return match ($text) {
                    'true' => true,
                    'false' => false,
                    'null' => null,
                    default => new Name('@' . $text), // a bare keyword (only seen in malformed files)
                };
        }

        return null;
    }

    public static function num(string $t): int|float
    {
        $t = preg_replace('/^([+-]?)[+-]+/', '$1', $t); // "--5" seen in the wild
        if (preg_match('/^[+-]?\d+$/', $t)) {
            return (int) $t;
        }

        return (float) $t;
    }

    public static function name(string $raw): string
    {
        $s = substr($raw, 1);

        return str_contains($s, '#') ? preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn ($m) => chr(hexdec($m[1])), $s) : $s;
    }

    /** Resolve backslash escapes of a literal string body. */
    public static function unescape(string $s): string
    {
        if (! str_contains($s, '\\') && ! str_contains($s, "\r")) {
            return $s;
        }
        $out = '';
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $c = $s[$i];
            if ($c === "\r") { // EOL in a string is always \n
                $out .= "\n";
                if (($s[$i + 1] ?? '') === "\n") {
                    $i++;
                }

                continue;
            }
            if ($c !== '\\') {
                $out .= $c;

                continue;
            }
            $c = $s[++$i] ?? '';
            switch ($c) {
                case 'n': $out .= "\n"; break;
                case 'r': $out .= "\r"; break;
                case 't': $out .= "\t"; break;
                case 'b': $out .= "\x08"; break;
                case 'f': $out .= "\f"; break;
                case "\r": if (($s[$i + 1] ?? '') === "\n") { $i++; } break; // line continuation
                case "\n": break;
                default:
                    if ($c >= '0' && $c <= '7') {
                        $o = $c;
                        while (strlen($o) < 3 && ($s[$i + 1] ?? '') >= '0' && ($s[$i + 1] ?? '') <= '7') {
                            $o .= $s[++$i];
                        }
                        $out .= chr(octdec($o) & 0xFF);
                    } else {
                        $out .= $c;
                    }
            }
        }

        return $out;
    }
}
