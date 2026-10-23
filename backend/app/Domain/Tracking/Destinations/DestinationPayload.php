<?php

namespace App\Domain\Tracking\Destinations;

use App\Domain\Conversions\Hashing;
use App\Domain\Tracking\EventType;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\Project;
use App\Models\TenantSetting;
use App\Models\TrackingDestination;
use App\Models\TrackingEvent;
use App\Models\TrackingLink;

/**
 * What a destination is told about one event — in one neutral shape, which each adapter then puts in its own words.
 *
 * Deliberately short. It carries what an optimiser or an analyst needs (what happened, when, for which project and
 * link, at what stage, worth how much) and the identifiers a platform needs to credit the right ad — and nothing else:
 * never a name, a note, a budget, a phone number or an email address in the clear. A hashed email or phone is added
 * only when the workspace allows it for this destination AND the visitor consented; so are the platform click ids.
 */
class DestinationPayload
{
    /**
     * @return array<string,mixed>
     */
    public function build(TrackingEvent $event, ?Lead $lead, TrackingDestination $destination, string $consent): array
    {
        $type = EventType::from($event->event_type);
        $link = $event->tracking_link_id ? TrackingLink::withTrashed()->find($event->tracking_link_id) : null;
        $money = $destination->send_value ? $this->money($type, $event, $lead) : [];

        $payload = [
            'event' => $type->canonical(),
            'event_id' => $event->event_id,
            'occurred_at' => $event->occurred_at->toIso8601String(),
            'visitor_id' => $event->visitor_id ?? $lead?->visitor_id,
            'session_id' => $event->session_id,
            'project_id' => $event->project_id ? Project::withTrashed()->whereKey($event->project_id)->value('uuid') : null,
            'tracking_link_id' => $link?->uuid,
            'campaign_id' => $event->campaign_id ? Campaign::whereKey($event->campaign_id)->value('uuid') : null,
            'platform' => $event->platform,
            'lead_stage' => $lead?->status?->value,
            'url' => $event->url,
            'utm' => array_filter([
                'source' => $event->utm_source ?? $link?->utm_source, 'medium' => $event->utm_medium ?? $link?->utm_medium,
                'campaign' => $event->utm_campaign ?? $link?->utm_campaign, 'content' => $event->utm_content ?? $link?->utm_content, 'term' => $event->utm_term ?? $link?->utm_term,
            ]),
        ] + $money;

        // Identifiers: only with the visitor's consent, and only if the workspace wants this destination to see them.
        if ($consent === 'granted') {
            $payload['click_ids'] = $this->clickIds($lead, $event);

            if ($destination->hash_identifiers && $lead !== null) {
                $payload['identifiers'] = array_filter(['em' => Hashing::email($lead->email), 'ph' => Hashing::phone($lead->phone_normalized ?? $lead->phone)]);
            }
        }

        return array_filter($payload, fn ($v) => $v !== null && $v !== [] && $v !== '');
    }

    /** @return array{value?:float, currency?:string} */
    private function money(EventType $type, TrackingEvent $event, ?Lead $lead): array
    {
        if (! in_array($type, [EventType::Won, EventType::Revenue], true)) {
            return [];
        }

        $minor = (int) ($event->meta['deal_value_minor'] ?? $lead?->deal_value_minor ?? 0);

        return $minor > 0 ? ['value' => round($minor / 100, 2), 'currency' => (string) (TenantSetting::query()->value('currency') ?: 'SAR')] : [];
    }

    /** @return array<string,string> the click ids of the last touch, then the first for any the last lacked */
    private function clickIds(?Lead $lead, TrackingEvent $event): array
    {
        $ids = (array) ($lead?->click_ids['first'] ?? []);
        $ids = array_merge($ids, (array) ($lead?->click_ids['last'] ?? []));

        return $ids !== [] ? $ids : array_filter(['gclid' => $event->click_id]);
    }
}
