<?php

namespace App\Http\Middleware;

use App\Domain\Mail\MailerRegistry;
use App\Http\Controllers\Api\V1\Platform\ImpersonationController;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Someone whose address is unproven works normally, but what reaches outside the company or brings others in waits until
 * it is proven. It answers only while mail really goes out: with no provider nobody could ever prove anything. The
 * platform's own accounts and a support session are not asked.
 */
class RequireVerifiedEmail
{
    /** [method, path pattern] that need a proven address. */
    private const GUARDED = [
        ['POST', 'api/v1/exports*'],
        ['POST', 'api/v1/invitations*'],
        ['POST', 'api/v1/users'],
        ['POST', 'api/v1/users/*'],
        ['POST', 'api/v1/report-shares*'],
        ['POST', 'api/v1/api-keys*'],
        ['PATCH', 'api/v1/api-keys/*'],
        ['POST', 'api/v1/webhook-subscriptions*'],
        ['PATCH', 'api/v1/webhook-subscriptions/*'],
        ['POST', 'api/v1/webhook-endpoints/*'],
        ['PATCH', 'api/v1/settings/security'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->tenant_id === null || $user->email_verified_at !== null
            || ! config('platform.require_verified_email') || ! app(MailerRegistry::class)->delivers()) {
            return $next($request);
        }

        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken && $token->name === ImpersonationController::TOKEN_NAME) {
            return $next($request);
        }

        foreach (self::GUARDED as [$method, $pattern]) {
            if ($request->isMethod($method) && $request->is($pattern)) {
                return response()->json([
                    'success' => false,
                    'data' => ['code' => 'email_verification_required'],
                    'message' => 'Confirm your email first: Profile, then Send the link.',
                    'errors' => null,
                ], 403);
            }
        }

        return $next($request);
    }
}
