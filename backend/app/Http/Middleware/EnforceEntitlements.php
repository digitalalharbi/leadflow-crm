<?php

namespace App\Http\Middleware;

use App\Domain\Access\WorkspaceModules;
use App\Domain\Billing\Entitlements;
use App\Domain\Billing\FeatureGate;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A feature the company's plan does not include is refused here, whatever the interface shows. The interface hides it
 * too, but this is the line that holds.
 */
class EnforceEntitlements
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->context->get();
        $feature = FeatureGate::for($request);

        if (($module = WorkspaceModules::blocking($request, $tenant)) !== null) {
            return response()->json([
                'success' => false,
                'data' => ['code' => 'module_disabled'],
                'message' => 'This is switched off for your workspace.',
                'errors' => ['module' => [$module]],
            ], 403);
        }

        if ($tenant === null || $feature === null) {
            return $next($request);
        }

        if (! Entitlements::for($tenant)->has($feature)) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Your plan does not include this feature.',
                'errors' => ['feature' => [$feature]],
            ], 403);
        }

        return $next($request);
    }
}
