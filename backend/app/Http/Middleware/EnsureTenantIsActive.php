<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A suspended tenant or an expired subscription locks the app out, read included. */
class EnsureTenantIsActive
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->context->get();

        if ($tenant === null) {
            return $next($request);
        }

        if (! $tenant->is_active) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'This account has been suspended. Please contact support.',
                'errors' => ['tenant' => ['suspended']],
            ], 403);
        }

        // Suspended, cancelled or lapsed: every row is kept, the workspace is closed until the subscription is.
        if (! $tenant->isSubscriptionUsable()) {
            $state = $tenant->lifecycleState();

            return response()->json([
                'success' => false,
                'data' => null,
                'message' => match ($state) {
                    'suspended' => 'This workspace is suspended until its subscription is settled.',
                    'cancelled' => 'This workspace\'s subscription was cancelled.',
                    default => 'Your subscription has expired.',
                },
                'errors' => [
                    'tenant' => [in_array($state, ['suspended', 'cancelled'], true) ? 'subscription_'.$state : 'subscription_expired'],
                    'state' => [$state],
                ],
            ], 402);
        }

        return $next($request);
    }
}
