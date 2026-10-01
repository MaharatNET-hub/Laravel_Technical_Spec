<?php

namespace App\Http\Middleware\V2;

use App\V2\Installer;
use Closure;
use Illuminate\Http\Request;

/** Hosts without a terminal: the first v2 request sets up the database (see App\V2\Installer). */
class EnsureInstalled
{
    public function handle(Request $request, Closure $next)
    {
        Installer::ensure();

        return $next($request);
    }
}
