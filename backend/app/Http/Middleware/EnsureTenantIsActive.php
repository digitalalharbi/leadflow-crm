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

        if (! $tenant->isSubscriptionUsable()) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Your subscription has expired.',
                'errors' => ['tenant' => ['subscription_expired']],
            ], 402);
        }

        return $next($request);
    }
}
