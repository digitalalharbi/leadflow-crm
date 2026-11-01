<?php

namespace App\Http\Middleware;

use App\Domain\Api\ApiKeys;
use App\Domain\Billing\Entitlements;
use App\Models\ApiKey;
use App\Models\Tenant;
use App\Support\ApiResponse;
use App\Support\ProjectContext;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public API's door: a company's key, in `Authorization: Bearer mht_…`, opens that company's data and nothing else.
 *
 * The key sets the tenant context for the request (so every scope that protects the app protects the API) and clears it
 * afterwards. `api.key:leads:read` names the scope the route needs. Reading needs the plan's `api` feature; sending a lead
 * in does not, because a company never loses an enquiry over its plan.
 */
class AuthenticateApiKey
{
    public function __construct(private ApiKeys $keys, private TenantContext $context, private ProjectContext $projects) {}

    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $key = $this->keys->resolve($request->bearerToken());

        if (! $key) {
            return ApiResponse::error('Invalid or expired API key.', null, 401);
        }

        // A key limited to its integration's addresses refuses every other one — a leaked key is then of no use.
        if (! $key->allowsIp($request->ip())) {
            return ApiResponse::error('This key may not be used from this address.', null, 403);
        }

        $tenant = $this->context->withoutScope(fn () => Tenant::query()->find($key->tenant_id));

        if (! $tenant || ! $tenant->is_active) {
            return ApiResponse::error('This workspace is not active.', null, 403);
        }

        if (! $key->allows($scope)) {
            return ApiResponse::error("This key does not have the {$scope} scope.", null, 403);
        }

        if ($scope !== 'leads:write' && ! Entitlements::for($tenant)->has('api')) {
            return ApiResponse::error('The API is not included in this workspace’s plan.', null, 402);
        }

        $this->touch($key, $request);
        $this->context->set($tenant);
        $request->attributes->set('api_key', $key);

        try {
            return $next($request);
        } finally {
            $this->context->clear();
            $this->projects->clear();
        }
    }

    /** Last use, at most once a minute: a busy integration should not write a row per call. */
    private function touch(ApiKey $key, Request $request): void
    {
        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();
        }
    }
}
