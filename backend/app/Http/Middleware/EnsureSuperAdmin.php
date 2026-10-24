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
        // A platform account AND a session issued by the platform's own door (it carries the `platform` ability):
        // an enrol-only session, or any token that did not come from the platform login, is refused like anyone else.
        $user = $request->user();

        if (! $user?->isSuperAdmin() || ! $user->tokenCan('platform')) {
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
