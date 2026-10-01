<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // the demo has no login: its JSON API is called by the page itself
        $middleware->validateCsrfTokens(except: ['api/*']);
        // free/shared hosts terminate HTTPS at a proxy: trust it so links keep https://
        $middleware->trustProxies(at: '*');
        // v2 admin: guests go to its login page
        $middleware->redirectGuestsTo(fn () => route('v2.admin.login'));
        $middleware->redirectUsersTo(fn () => route('v2.admin.dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
