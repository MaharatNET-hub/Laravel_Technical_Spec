<?php

namespace App\Review;

/** File-based storage for the demo (no database needed): storage/app/demo/... */
final class Store
{
    public static function dir(string $sub = ''): string
    {
        $d = storage_path('app/demo' . ($sub !== '' ? '/' . $sub : ''));
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
        foreach ([self::path('sample/submittal.pdf'), self::path('submittal.pdf'), base_path('submittal.pdf')] as $f) {
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
