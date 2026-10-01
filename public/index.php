<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// First run on a host without a terminal: create .env from .env.example with its own key.
$base = dirname(__DIR__);
if (! is_file("$base/.env") && is_file("$base/.env.example")) {
    @copy("$base/.env.example", "$base/.env");
}
if (is_file("$base/.env") && preg_match('/^APP_KEY=\s*$/m', $env = (string) file_get_contents("$base/.env"))) {
    @file_put_contents("$base/.env", preg_replace('/^APP_KEY=\s*$/m', 'APP_KEY=base64:'.base64_encode(random_bytes(32)), $env, 1));
}

// Served through the root .htaccess (web root = project folder, maybe a sub-folder): let Laravel see the
// install folder as its base path instead of ".../public".
$script = $_SERVER['SCRIPT_NAME'] ?? '';
if (str_ends_with($script, '/public/index.php') && ! str_contains(strtok($_SERVER['REQUEST_URI'] ?? '', '?'), '/public/')) {
    $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = substr($script, 0, -strlen('/public/index.php')).'/index.php';
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
(require_once __DIR__.'/../bootstrap/app.php')
    ->handleRequest(Request::capture());
