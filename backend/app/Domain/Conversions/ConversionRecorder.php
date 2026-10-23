<?php

namespace App\Domain\Conversions;

use App\Domain\Campaigns\AdPlatform;
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
        // Facebook and Instagram leads belong to a Meta ad account: keyed by
        // the lead's own word they never matched an exporter and the sale was
        // never reported back to the campaign that produced it.
        $platform = AdPlatform::fromLeadPlatform($lead->platform)?->value;

        // Somebody who refused tracking is not reported to an advertising platform.
        if ($lead->tracking_consent === 'denied') {
            return null;
        }

        if (! $this->registry->has($platform)) {
            return null;
        }

        return ConversionExport::firstOrCreate(
            [
                'lead_id' => $lead->id,
                'platform' => $platform,
                'event' => $event->value,
            ],
            [
                'occurred_at' => now(),
                'value' => $event === ConversionEvent::Won ? $this->dealValue($lead) : null,
                'currency' => $lead->tenant?->settings?->currency ?? 'SAR',
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
        // What it actually closed for, when somebody recorded it. Only without
        // that does the buyer's stated budget stand in as an estimate.
        if ($lead->deal_value_minor !== null && $lead->deal_value_minor > 0) {
            return round($lead->deal_value_minor / 100, 2);
        }

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
