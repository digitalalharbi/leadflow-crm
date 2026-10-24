<?php

namespace App\Domain\Billing;

use App\Models\Plan;
use App\Models\Tenant;

/**
 * No payment gateway: the platform owner activates, extends and stops subscriptions from the console, and records the
 * invoice they sent. The same lifecycle a gateway would drive, by hand.
 */
class ManualBillingProvider implements BillingProvider
{
    public function key(): string
    {
        return 'manual';
    }

    public function collectsPayment(): bool
    {
        return false;
    }

    public function activate(Tenant $tenant, Plan $plan, string $cycle, ?\DateTimeInterface $until): void
    {
        $tenant->forceFill([
            'plan_id' => $plan->id,
            'plan' => $plan->key,
            'billing_cycle' => $cycle,
            'billing_provider' => $this->key(),
            'subscription_status' => 'active',
            'subscription_ends_at' => $until,
            'suspended_at' => null,
            'cancelled_at' => null,
        ])->save();
    }

    public function cancel(Tenant $tenant, ?string $reason): void
    {
        $tenant->forceFill([
            'subscription_status' => 'cancelled',
            'cancelled_at' => now(),
            'status_reason' => $reason,
        ])->save();
    }
}
