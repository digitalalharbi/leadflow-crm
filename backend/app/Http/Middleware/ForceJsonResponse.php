<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** API clients always get JSON, never an HTML error page or a redirect to /login. */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next)
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
