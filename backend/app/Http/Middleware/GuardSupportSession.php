<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Api\V1\Platform\ImpersonationController;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * A support session works inside a company as one of its people — but never touches what belongs to that person alone
 * or what would outlive the session: their password, their other sessions, the company's keys, the team itself.
 */
class GuardSupportSession
{
    /** [method, path pattern] a support session may not call. */
    private const REFUSED = [
        ['POST', 'api/v1/auth/password'],
        ['POST', 'api/v1/auth/logout-all'],
        ['PATCH', 'api/v1/settings/security'],
        ['POST', 'api/v1/auth/calendar-feed/rotate'],
        ['DELETE', 'api/v1/users/*'],
        ['POST', 'api/v1/users'],
        ['POST', 'api/v1/invitations*'],
        ['POST', 'api/v1/webhook-endpoints/*/rotate'],
        ['POST', 'api/v1/integrations/*/rotate*'],
        ['PATCH', 'api/v1/integrations/*'],
        ['POST', 'api/v1/auth/workspaces/*'],
        ['POST', 'api/v1/auth/security/*'],
        ['DELETE', 'api/v1/auth/security/*'],
        ['POST', 'api/v1/auth/email/*'],
        ['PATCH', 'api/v1/users/*'],
        ['POST', 'api/v1/users/*'],
        ['POST', 'api/v1/api-keys*'],
        ['PATCH', 'api/v1/api-keys/*'],
        ['DELETE', 'api/v1/api-keys/*'],
        ['POST', 'api/v1/webhook-subscriptions*'],
        ['PATCH', 'api/v1/webhook-subscriptions/*'],
        ['DELETE', 'api/v1/webhook-subscriptions/*'],
        ['POST', 'api/v1/access/*'],
        ['PATCH', 'api/v1/access/*'],
        ['PUT', 'api/v1/access/*'],
        ['DELETE', 'api/v1/access/*'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken || $token->name !== ImpersonationController::TOKEN_NAME) {
            return $next($request);
        }

        foreach (self::REFUSED as [$method, $pattern]) {
            if ($request->isMethod($method) && $request->is($pattern)) {
                return response()->json([
                    'success' => false,
                    'data' => null,
                    'message' => 'Not available during a support session.',
                    'errors' => ['support_session' => ['refused']],
                ], 403);
            }
        }

        return $next($request);
    }
}
