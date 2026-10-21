<?php

namespace App\Http\Middleware;

use App\Domain\Access\Permissions;
use App\Domain\Access\RoutePermission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The API's half of "hide it AND block it".
 *
 * For the specialised roles every request must be granted by the permission matrix; a route no
 * area claims is refused, so a new endpoint starts closed to them rather than open. The
 * long-standing roles are not touched here — their policies have always decided, and changing
 * that behaviour is not what this is for.
 */
class EnforcePermissions
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->role?->usesMatrix()) {
            return $next($request);
        }

        $needs = RoutePermission::for($request);

        // Anybody may update their own profile (away until, locale): it is not a change to the team.
        if ($request->isMethod('PATCH') && $request->route('uuid') !== null && $request->route('uuid') === $user->uuid && str_starts_with($request->path(), 'api/v1/users/')) {
            return $next($request);
        }

        if ($needs === '') {
            return $next($request);
        }

        if ($needs === null || ! Permissions::has($user, $needs)) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Your role does not include this.',
                'errors' => null,
            ], 403);
        }

        return $next($request);
    }
}
