<?php

namespace App\Http\Middleware\V2;

use Closure;
use Illuminate\Http\Request;

/** The dashboard is in English (like the review tool and the issued documents), whatever the site language. */
class AdminLocale
{
    public function handle(Request $request, Closure $next)
    {
        app()->setLocale('en');

        return $next($request);
    }
}
