<?php

namespace App\Http\Middleware;

use App\Domain\Access\AccessResolver;
use App\Domain\Access\RoutePermission;
use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The API's half of "hide it AND block it".
 *
 * For the specialised roles (and any role a workspace built) every request must be granted by the
 * permission table; a route no area claims is refused, so a new endpoint starts closed to them
 * rather than open. The long-standing roles keep their policies — the table never widens them —
 * but what their workspace has explicitly taken away is refused here too.
 */
class EnforcePermissions
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->role === null) {
            return $next($request);
        }

        $matrix = $user->usesMatrix();
        $needs = RoutePermission::for($request);

        // Anybody may update their own profile (away until, locale): it is not a change to the team.
        if ($request->isMethod('PATCH') && $request->route('uuid') !== null && $request->route('uuid') === $user->uuid && str_starts_with($request->path(), 'api/v1/users/')) {
            return $next($request);
        }

        if ($needs === '') {
            return $next($request);
        }

        $access = AccessResolver::resolve($user, $this->projectId($request));

        $refused = $matrix ? ($needs === null || ! $access->has($needs)) : ($needs !== null && in_array($needs, $access->denied, true));

        if ($refused) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Your role does not include this.',
                'errors' => null,
            ], 403);
        }

        return $next($request);
    }

    /** The project a request is about, when it names one — project overrides apply there. */
    private function projectId(Request $request): ?int
    {
        $uuid = $request->route('uuid') !== null && str_starts_with($request->path(), 'api/v1/projects/')
            ? $request->route('uuid')
            : $request->input('project_id', $request->query('project_id'));

        if (! is_string($uuid) || ! preg_match('/^[0-9a-f-]{36}$/i', $uuid)) {
            return null;
        }

        // A project this person was not given brings them none of its overrides, whatever the request names.
        $id = Project::withoutGlobalScopes()->where('tenant_id', $request->user()->tenant_id)->where('uuid', $uuid)->value('id');

        return $id !== null && $request->user()->reaches($id) ? $id : null;
    }
}
