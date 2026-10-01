<?php

namespace App\V2;

use Database\Seeders\V2Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * Creates the SQLite file (if used), runs the migrations and seeds the v2 content — once, under a
 * lock, and again only when a new migration file appears. Used by the first web request (hosts
 * without a terminal) and by `php artisan v2:install` (container start).
 */
final class Installer
{
    public static function ensure(): bool
    {
        $migrations = glob(database_path('migrations/*.php')) ?: [];
        $flag = storage_path('app/v2/installed-' . md5(implode('|', array_map('basename', $migrations))));
        if (is_file($flag)) {
            return false;
        }
        File::ensureDirectoryExists(storage_path('app/v2'));
        $lock = fopen(storage_path('app/v2/install.lock'), 'c');
        flock($lock, LOCK_EX);
        try {
            if (is_file($flag)) {
                return false;
            }
            if (config('database.default') === 'sqlite') {
                $db = config('database.connections.sqlite.database');
                if ($db && $db !== ':memory:' && ! is_file($db)) {
                    File::ensureDirectoryExists(dirname($db));
                    touch($db);
                }
            }
            @set_time_limit(120);
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('db:seed', ['--class' => V2Seeder::class, '--force' => true]);
            foreach (glob(storage_path('app/v2/installed-*')) ?: [] as $old) {
                @unlink($old);
            }
            touch($flag);

            return true;
        } finally {
            flock($lock, LOCK_UN);
        }
    }
}
