<?php

namespace App\Listeners;

use App\Events\Contracts\LeadDomainEvent;
use App\Events\LeadFeedUpdate;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;

/**
 * Tells the screens that are already open.
 *
 * The work queue refetches every sixty seconds, which is the right floor for a
 * screen left open all day and the wrong answer for the one thing this product
 * promises: a lead that arrives at 10:04 should be on somebody's screen at
 * 10:04, not at 10:05. A minute is also exactly long enough for a rep to walk
 * away from the desk.
 *
 * Only the events that change what a person should do next. A stage change is
 * usually the person on that screen doing it, and telling them about their own
 * click is how a live feed becomes something people turn off.
 *
 * Not queued. It does nothing but assemble three strings and dispatch an event
 * that queues itself — putting a job in front of that would add a hop to the
 * one path whose whole point is arriving quickly.
 */
class BroadcastLeadChange
{
    private const WORTH_SAYING = [
        // A new customer is waiting. The only one a manager watches for.
        'lead.created',
        // It is yours now — the rep's own queue just changed underneath them.
        'lead.assigned',
        // They answered. The most perishable thing that happens here.
        'lead.replied',
    ];

    public function handle(LeadDomainEvent $event): void
    {
        if (! in_array($event->name(), self::WORTH_SAYING, true)) {
            return;
        }

        $lead = $event->lead();

        $tenantUuid = $this->tenantUuid($lead);

        // A lead with no workspace cannot be fed to one. This should not happen
        // — BelongsToTenant refuses to save without it — so it is a guard, not
        // a case: better a feed that stays quiet than one that throws inside
        // whatever was recording the outcome.
        if ($tenantUuid === null) {
            return;
        }

        LeadFeedUpdate::dispatch(
            $event->name(),
            $lead->uuid,
            $tenantUuid,
            $this->assigneeUuid($lead),
        );
    }

    /**
     * Read without the tenant scope on purpose.
     *
     * This runs wherever a lead event is raised — a queued job, a scheduled
     * sweep, an import — and the scope fails closed outside a request. Scoped,
     * it would find no tenant for a lead that plainly has one, and the feed
     * would go silent in exactly the places that need it most.
     */
    private function tenantUuid(Lead $lead): ?string
    {
        return Tenant::withoutGlobalScopes()->whereKey($lead->tenant_id)->value('uuid');
    }

    private function assigneeUuid(Lead $lead): ?string
    {
        if ($lead->assigned_to === null) {
            return null;
        }

        return User::withoutGlobalScopes()->whereKey($lead->assigned_to)->value('uuid');
    }
}
