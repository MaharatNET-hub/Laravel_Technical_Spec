<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// v2: set up the database (create SQLite file, migrate, seed site content) — same lock as the first web request
Artisan::command('v2:install', function () {
    $this->info(\App\V2\Installer::ensure() ? 'v2 installed (database migrated and seeded).' : 'v2 already installed.');
})->purpose('Set up the v2 database and content');
