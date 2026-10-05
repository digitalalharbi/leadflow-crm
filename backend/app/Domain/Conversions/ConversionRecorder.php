<?php

namespace App\Domain\Conversions;

use App\Models\ConversionExport;
use App\Models\Lead;

/**
 * Turns a sales outcome into a row waiting to be uploaded.
 *
 * Recording and sending are separate on purpose. A workspace that connects Meta
 * next month should be able to upload last month's conversions, and a platform
 * that is down for an hour must not cost anybody a sale record. So the outcome
 * is written the moment it happens, whatever the state of the integration.
 */
class ConversionRecorder
{
    public function __construct(private readonly ExporterRegistry $registry) {}

    /**
     * @return ConversionExport|null null when there is nowhere to send it
     */
    public function record(Lead $lead, ConversionEvent $event): ?ConversionExport
    {
        // A lead with no platform came from a spreadsheet or a phone call;
        // there is no ad account waiting to hear about it.
        if (! $this->registry->has($lead->platform)) {
            return null;
        }

        return ConversionExport::firstOrCreate(
            [
                'lead_id' => $lead->id,
                'platform' => $lead->platform,
                'event' => $event->value,
            ],
            [
                'occurred_at' => now(),
                'value' => $event === ConversionEvent::Won ? $this->dealValue($lead) : null,
                'currency' => 'SAR',
                'status' => $this->matchable($lead)
                    ? ConversionExport::PENDING
                    : ConversionExport::UNMATCHABLE,
            ],
        );
    }

    /**
     * Is there anything the platform could match on?
     *
     * Uploading a conversion with no identifier is not a failure that shows up
     * anywhere — the platform accepts it, matches nothing, and the workspace
     * sees a successful upload that improved nothing. Better to mark it and say
     * so on the data quality screen.
     */
    private function matchable(Lead $lead): bool
    {
        return filled($lead->click_id)
            || filled($lead->phone_normalized)
            || filled($lead->email)
            || filled($lead->provider_lead_id);
    }

    /**
     * What the deal was worth.
     *
     * The midpoint of the stated budget, which is an estimate and is treated as
     * one: the optimiser needs relative value — this buyer was worth more than
     * that one — far more than it needs an exact figure nobody recorded.
     */
    private function dealValue(Lead $lead): ?float
    {
        $min = $lead->budget_min;
        $max = $lead->budget_max;

        return match (true) {
            $min !== null && $max !== null => round(((float) $min + (float) $max) / 2, 2),
            $min !== null => (float) $min,
            $max !== null => (float) $max,
            default => null,
        };
    }
}
