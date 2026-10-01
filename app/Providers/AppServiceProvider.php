<?php

namespace App\Providers;

use App\Models\V2\Submission;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // v2: {submission} is always a Submission, whatever the controller signature
        // (the admin workspace reuses the v1 tool's controller methods).
        Route::model('submission', Submission::class);
        // separate counters per purpose (plain throttle:x,y shares one counter per IP across routes)
        foreach (['v2-contact' => 6, 'v2-submit' => 10, 'v2-track' => 40, 'v2-login' => 6] as $name => $perMinute) {
            RateLimiter::for($name, fn (Request $r) => Limit::perMinute($perMinute)->by($name . '|' . $r->ip()));
        }
        Paginator::defaultSimpleView('v2.admin.partials.pager');
        Paginator::defaultView('v2.admin.partials.pager');
    }
}
