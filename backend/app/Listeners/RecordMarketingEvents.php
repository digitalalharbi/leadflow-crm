<?php

namespace App\Listeners;

use App\Domain\Leads\InterestEngine;
use App\Domain\Tracking\EventRecorder;
use App\Domain\Tracking\EventType;
use App\Events\Contracts\LeadDomainEvent;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Puts the journey of a lead on the marketing trail: created, qualified, interested, a visit booked.
 *
 * One listener for every lead event, like the automation's, so a stage reached by a rep, by a rule or by an import is
 * the same event. Each is recorded once (a lead that flaps between "interested" and "hot" is interested once), with the
 * link, session, campaign, platform and consent the lead carries — and the recorder tells the destinations. The sale, its
 * revenue and a lost lead are recorded where the status changes (see Lead), because that is the one place every path goes through.
 */
class RecordMarketingEvents implements ShouldQueue
{
    public int $tries = 3;

    public array $backoff = [10, 60];

    public function __construct(private readonly EventRecorder $events, private readonly TenantContext $tenants) {}

    public function handle(LeadDomainEvent $event): void
    {
        $lead = $event->lead();
        $context = (array) ($event->context ?? []);

        $outcome = match (true) {
            $event->name() === 'lead.created' => [EventType::LeadCreated, [], null],
            $event->name() === 'lead.qualified' => [EventType::Qualified, [], null],
            $event->name() === 'lead.interest_changed' && in_array($context['to'] ?? null, InterestEngine::INTERESTED, true) => [EventType::Interested, ['level' => $context['to']], null],
            $event->name() === 'lead.appointment_created' => [EventType::AppointmentBooked, array_filter(['appointment' => $context['appointment'] ?? null]), $context['appointment'] ?? null],
            default => null,
        };

        if ($outcome === null || ! $lead->tenant) {
            return;
        }

        [$type, $meta, $uniqueBy] = $outcome;

        // Queued work carries no request: the tenant is set, or every scoped query fails closed and nothing is written.
        $this->tenants->withTenant($lead->tenant, fn () => $this->events->outcome($lead->fresh() ?? $lead, $type, $meta, $uniqueBy));
    }
}
