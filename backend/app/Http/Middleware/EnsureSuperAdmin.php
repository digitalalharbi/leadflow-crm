<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The platform console. Tenant users must never see this surface at all. */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Resource not found.',
                'errors' => null,
            ], 404);
        }

        return $next($request);
    }
}
