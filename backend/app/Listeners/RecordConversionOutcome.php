<?php

namespace App\Listeners;

use App\Domain\Conversions\ConversionEvent;
use App\Domain\Conversions\ConversionRecorder;
use App\Enums\LeadStatus;
use App\Events\Contracts\LeadDomainEvent;
use App\Events\LeadStageChanged;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Writes the conversion row when a lead reaches an outcome worth reporting.
 *
 * Listens to the domain events rather than hooking the controllers, so an
 * outcome recorded by a rep, by an automation rule, or by an import all count
 * the same. The one that would otherwise be missed is automation — a rule that
 * moves a lead to won is still a won lead.
 */
class RecordConversionOutcome implements ShouldQueue
{
    public int $tries = 3;

    public array $backoff = [10, 60];

    public function __construct(
        private readonly ConversionRecorder $recorder,
        private readonly TenantContext $tenants,
    ) {}

    public function handle(LeadDomainEvent $event): void
    {
        $outcome = match (true) {
            $event->name() === 'lead.qualified' => ConversionEvent::Qualified,
            $event->name() === 'lead.won' => ConversionEvent::Won,
            // A booked visit is the signal between "qualified" and "sold", and
            // the one an algorithm can learn from within days rather than months.
            $event instanceof LeadStageChanged && $event->to === LeadStatus::Appointment => ConversionEvent::Appointment,
            default => null,
        };

        if (! $outcome) {
            return;
        }

        // Queued work carries no request, so the tenant is set explicitly or
        // every scoped query in here fails closed and silently does nothing.
        $lead = $event->lead();

        $this->tenants->withTenant($lead->tenant, function () use ($lead, $outcome) {
            $this->recorder->record($lead, $outcome);
        });
    }
}
