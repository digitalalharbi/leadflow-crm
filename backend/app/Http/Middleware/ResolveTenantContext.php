<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the tenant for the request from the authenticated user — and only from
 * the authenticated user. A client cannot select a tenant via header, body or
 * query string; that is the whole point.
 *
 * Super admins carry no tenant and operate through the unscoped super-admin API.
 */
class ResolveTenantContext
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if ($user->isSuperAdmin()) {
            $this->context->set(null);

            return $next($request);
        }

        $tenant = $user->tenant()->first();

        if ($tenant === null) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'This account is not linked to an organisation.',
                'errors' => null,
            ], 403);
        }

        $this->context->set($tenant);
        app()->setLocale($user->locale ?? $tenant->settings?->locale ?? config('app.locale'));

        // Deliberately NOT calling date_default_timezone_set() here. Timestamps are
        // stored and returned in UTC; the SPA renders them in the tenant's timezone
        // (served in the branding payload). Mutating PHP's global timezone per
        // request shifts stored values and leaks between jobs in a queue worker.

        return $next($request);
    }
}
