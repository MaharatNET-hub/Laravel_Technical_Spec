<?php

namespace App\Http\Middleware\V2;

use App\Models\V2\Submission;
use App\Review\Store;
use Closure;
use Illuminate\Http\Request;

/** Point the review engine at one submission's private folder for this request. */
class ScopeSubmission
{
    public function handle(Request $request, Closure $next)
    {
        $s = $request->route('submission');
        abort_unless($s instanceof Submission, 404);
        Store::setBase($s->dir());
        try {
            return $next($request);
        } finally {
            Store::setBase(null);
        }
    }
}
