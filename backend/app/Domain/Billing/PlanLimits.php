<?php

namespace App\Domain\Billing;

use App\Models\Tenant;
use Illuminate\Validation\ValidationException;

/**
 * The hard limits of a subscription: seats and projects. Checked by the action that would cross them, on the server.
 * Nothing already there is ever taken away — a limit only refuses the next one.
 *
 * Leads are deliberately soft: a lead that arrives over the month's allowance is still captured and raises a usage
 * alert. Refusing a customer's enquiry would cost the company more than the plan does.
 */
class PlanLimits
{
    public static function ensureSeatFor(Tenant $tenant, ?string $role, int $seats = 1): void
    {
        if ($role !== null && in_array($role, config('billing.seatless_roles'), true)) {
            return;
        }

        $limit = Entitlements::for($tenant)->seatLimit();

        if ($limit === null) {
            return;
        }

        $meter = UsageMeter::for($tenant);

        if ($meter->activeSeats() + $meter->pendingSeats() + $seats > $limit) {
            throw ValidationException::withMessages([
                'seats' => ["Every seat on this plan is taken ({$limit}). Add seats or upgrade the plan to add more people."],
            ]);
        }
    }

    public static function ensureProjectRoom(Tenant $tenant): void
    {
        $entitlements = Entitlements::for($tenant);
        $count = UsageMeter::for($tenant)->projects();

        if ($count >= 1 && ! $entitlements->has('multiple_projects')) {
            throw ValidationException::withMessages([
                'project' => ['This plan includes one project. Upgrade the plan to manage more than one.'],
            ]);
        }

        $limit = $entitlements->limit('projects');

        if ($limit !== null && $count >= $limit) {
            throw ValidationException::withMessages([
                'project' => ["This plan includes {$limit} projects, and all of them are in use."],
            ]);
        }
    }
}
