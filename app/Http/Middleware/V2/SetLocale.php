<?php

namespace App\Http\Middleware\V2;

use Closure;
use Illuminate\Http\Request;

/** Arabic / English for v2: ?lang=xx switches and is remembered in the session. */
class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        if (in_array($request->query('lang'), ['ar', 'en'], true)) {
            $request->session()->put('v2_locale', $request->query('lang'));
        }
        app()->setLocale($request->session()->get('v2_locale', config('v2.locale', 'ar')));

        return $next($request);
    }
}
