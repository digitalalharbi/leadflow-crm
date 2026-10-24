<?php

namespace App\Domain\Billing;

use App\Models\Tenant;

/**
 * The subscription as a workspace sees it — sent with the session, so the interface hides what the plan does not
 * include and shows the lifecycle banner. The server enforces the same facts on its own (EnforceEntitlements,
 * EnsureTenantIsActive, PlanLimits); this is only what the screen needs to agree with it.
 */
class SubscriptionSummary
{
    public static function for(Tenant $tenant, bool $withUsage = false): array
    {
        $entitlements = Entitlements::for($tenant);
        $plan = $entitlements->plan();

        return array_filter([
            'plan' => $plan ? ['key' => $plan->key, 'name' => $plan->name] : null,
            'status' => $tenant->subscription_status,
            'state' => $tenant->lifecycleState(),
            'usable' => $tenant->isSubscriptionUsable(),
            'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
            'subscription_ends_at' => $tenant->subscription_ends_at?->toIso8601String(),
            'features' => $entitlements->features(),
            'limits' => $entitlements->limits(),
            'seat_limit' => $entitlements->seatLimit(),
            'usage' => $withUsage ? UsageMeter::for($tenant)->summary($entitlements) : null,
        ], fn ($v, $k) => $k !== 'usage' || $v !== null, ARRAY_FILTER_USE_BOTH);
    }
}
