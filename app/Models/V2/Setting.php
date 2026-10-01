<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;

/** Key/value site settings: company profile, contact details, defaults. */
class Setting extends Model
{
    protected $table = 'v2_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected $casts = ['value' => 'array'];

    private static ?array $cache = null;

    public static function allValues(): array
    {
        return self::$cache ??= self::query()->pluck('value', 'key')->map(fn ($v) => is_string($v) ? json_decode($v, true) : $v)->all();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::allValues()[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        self::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        self::$cache = null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
