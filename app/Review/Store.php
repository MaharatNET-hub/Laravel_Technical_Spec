<?php

namespace App\Review;

/**
 * File-based storage for a review (no database needed). v1 uses storage/app/demo; v2 points it at
 * one folder per submission with Store::using().
 */
final class Store
{
    /** Absolute base folder, or null for the v1 demo folder. */
    private static ?string $base = null;

    /** Run $fn with the store pointed at $dir (restored afterwards). */
    public static function using(string $dir, callable $fn): mixed
    {
        $prev = self::$base;
        self::$base = rtrim($dir, '/');
        try {
            return $fn();
        } finally {
            self::$base = $prev;
        }
    }

    public static function scoped(): bool
    {
        return self::$base !== null;
    }

    public static function setBase(?string $dir): void
    {
        self::$base = $dir === null ? null : rtrim($dir, '/');
    }

    public static function dir(string $sub = ''): string
    {
        $d = (self::$base ?? storage_path('app/demo')) . ($sub !== '' ? '/' . $sub : '');
        if (! is_dir($d)) {
            mkdir($d, 0775, true);
        }

        return $d;
    }

    public static function path(string $name): string
    {
        return self::dir() . '/' . $name;
    }

    public static function read(string $name, mixed $default = null): mixed
    {
        $f = self::path($name);
        if (! is_file($f)) {
            return $default;
        }
        $v = json_decode((string) file_get_contents($f), true);

        return $v ?? $default;
    }

    public static function write(string $name, mixed $value): void
    {
        $f = self::path($name);
        file_put_contents($f . '.tmp', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        rename($f . '.tmp', $f);
    }

    public static function delete(string ...$names): void
    {
        foreach ($names as $n) {
            @unlink(self::path($n));
        }
    }

    /** Project rules + disclosure config; seeded from resources/demo/rules.json on first use. */
    public static function rules(): array
    {
        $f = self::path('rules.json');
        if (! is_file($f)) {
            copy(resource_path('demo/rules.json'), $f);
        }

        return json_decode((string) file_get_contents($f), true);
    }

    public static function saveRules(array $cfg): void
    {
        self::write('rules.json', $cfg);
    }

    /** The sample submittal (copied to the server by FTP, never committed). */
    public static function samplePath(): ?string
    {
        $candidates = [self::path('sample/submittal.pdf'), self::path('submittal.pdf')];
        if (! self::scoped()) {
            $candidates[] = base_path('submittal.pdf');
        }
        foreach ($candidates as $f) {
            if (is_file($f)) {
                return $f;
            }
        }

        return null;
    }

    public static function clearDir(string $sub): void
    {
        foreach (glob(self::dir($sub) . '/*') ?: [] as $f) {
            @unlink($f);
        }
    }
}
