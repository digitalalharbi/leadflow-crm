<?php

namespace App\Http\Middleware;

use App\Domain\Auth\SecondFactor;
use App\Http\Controllers\Api\V1\Platform\ImpersonationController;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * When a workspace requires a second factor, a member without one can do one thing: set it up. Everything else answers
 * 403 `mfa_enrolment_required` until they have, so the rule holds at the API and not only in the interface.
 *
 * A support session is let through: the person giving support cannot enrol someone else's phone.
 */
class RequireWorkspaceMfa
{
    private const OPEN = ['api/v1/auth/me', 'api/v1/auth/logout', 'api/v1/auth/logout-all', 'api/v1/auth/security', 'api/v1/auth/impersonation/stop'];

    public function __construct(private SecondFactor $factor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // The tenant the context resolved for this request (settings usually already loaded), not a second lookup.
        if ($user === null || $user->tenant_id === null || $this->factor->enabled($user) || ! app(TenantContext::class)->get()?->settings?->require_mfa) {
            return $next($request);
        }

        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken && $token->name === ImpersonationController::TOKEN_NAME) {
            return $next($request);
        }

        $path = $request->path();

        foreach (self::OPEN as $open) {
            if ($path === $open || str_starts_with($path, $open.'/')) {
                return $next($request);
            }
        }

        return response()->json([
            'success' => false,
            'data' => ['code' => 'mfa_enrolment_required'],
            'message' => 'Your workspace requires a second factor. Set it up to continue.',
            'errors' => null,
        ], 403);
    }
}
