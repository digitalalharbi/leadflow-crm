<?php

namespace App\Domain\Billing;

use App\Models\Plan;
use App\Models\Tenant;

/**
 * What one company's subscription lets it do: its plan's features plus any add-on the owner switched on, and its plan's
 * limits unless the owner set a company-specific one. Read from the database each time — never from the browser.
 *
 * A company with no plan at all has no paid features: the gate fails closed.
 */
class Entitlements
{
    private function __construct(private Tenant $tenant, private ?Plan $plan) {}

    public static function for(Tenant $tenant): self
    {
        $plan = $tenant->relationLoaded('subscriptionPlan') ? $tenant->subscriptionPlan : ($tenant->plan_id ? $tenant->subscriptionPlan()->first() : null);

        return new self($tenant, $plan);
    }

    /** @return list<string> */
    public function features(): array
    {
        $features = array_merge($this->plan?->features ?? [], $this->tenant->addons ?? []);

        return array_values(array_intersect(Plan::FEATURES, array_unique($features)));
    }

    public function has(string $feature): bool
    {
        return in_array($feature, $this->features(), true);
    }

    /** A limit for this company; null is unlimited. */
    public function limit(string $key): ?int
    {
        $overrides = $this->tenant->limit_overrides ?? [];

        if (array_key_exists($key, $overrides)) {
            return $overrides[$key] === null ? null : (int) $overrides[$key];
        }

        $value = ($this->plan?->limits ?? [])[$key] ?? null;

        // No plan: one project, as a workspace with no subscription is not a multi-project one either.
        if ($this->plan === null && $key === 'projects') {
            return 1;
        }

        return $value === null ? null : (int) $value;
    }

    /** @return array<string, int|null> */
    public function limits(): array
    {
        return collect(Plan::LIMITS)->mapWithKeys(fn ($k) => [$k => $this->limit($k)])->all();
    }

    /**
     * Seats this company may fill: the owner's figure if set, otherwise the plan's included seats plus the seats it
     * bought, never past the plan's ceiling. Null is unlimited.
     */
    public function seatLimit(): ?int
    {
        if ($this->tenant->seat_limit_override !== null) {
            return (int) $this->tenant->seat_limit_override;
        }

        if ($this->plan === null) {
            return 1;
        }

        $seats = (int) $this->plan->included_seats + (int) $this->tenant->extra_seats;

        return $this->plan->max_seats !== null ? min($seats, (int) $this->plan->max_seats) : $seats;
    }

    public function plan(): ?Plan
    {
        return $this->plan;
    }
}
