<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The platform owner's own account routes (who am I, second factor, sign out): any session the platform login issued,
 * including the enrol-only one a required second factor leaves an un-enrolled owner with.
 */
class EnsurePlatformSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->isSuperAdmin() || ! ($user->tokenCan('platform') || $user->tokenCan('platform-enrol'))) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Resource not found.', 'errors' => null], 404);
        }

        return $next($request);
    }
}
