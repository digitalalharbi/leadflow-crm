<?php

namespace App\Domain\Billing;

use App\Models\Plan;
use App\Models\Tenant;

/**
 * The seam a payment provider plugs into. The subscription model (plan, status, seats, add-ons, invoices) belongs to
 * Mohtam; a provider only moves it — after a checkout, a renewal, a failed charge — and keeps its own reference on the
 * tenant (billing_customer_ref / billing_subscription_ref).
 */
interface BillingProvider
{
    public function key(): string;

    /** Whether this provider takes payment itself; the manual provider does not. */
    public function collectsPayment(): bool;

    /** Puts a company on a plan from a given date for a billing cycle. */
    public function activate(Tenant $tenant, Plan $plan, string $cycle, ?\DateTimeInterface $until): void;

    public function cancel(Tenant $tenant, ?string $reason): void;
}
