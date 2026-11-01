<?php

namespace App\Domain\Webhooks;

use App\Domain\Leads\InterestEngine;
use App\Domain\Tracking\Destinations\SafeUrl;
use App\Events\Contracts\LeadDomainEvent;
use App\Events\LeadStageChanged;
use App\Jobs\DeliverWebhook;
use App\Models\Lead;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Turns what happened inside a company into the events its own systems subscribed to, and queues one delivery per
 * subscription.
 *
 * The payload is deliberately small — UUIDs and the facts a CRM sync needs, never an internal id, a note or a message —
 * and identical for every subscriber. Each event carries an `event_id`; a delivery is unique per (subscription,
 * event_id), so a retried job or a replayed signal cannot deliver the same event twice.
 */
class OutboundWebhooks
{
    /** The outbound name for an internal event, or null for one nobody can subscribe to. */
    public function nameFor(LeadDomainEvent $event): ?string
    {
        $context = property_exists($event, 'context') ? $event->context : [];

        return match ($event->name()) {
            'lead.created' => 'lead.created',
            'lead.stage_changed' => 'lead.updated',
            'lead.qualified' => 'lead.qualified',
            'lead.assigned' => 'lead.assigned',
            'lead.won' => 'sale.created',
            'lead.lost' => 'lead.lost',
            'lead.appointment_created' => 'visit.created',
            'lead.reservation_created' => 'reservation.created',
            // Interest that crossed into "interested" or above, not every wobble of the score.
            'lead.interest_changed' => in_array($context['to'] ?? null, InterestEngine::INTERESTED, true)
                && ! in_array($context['from'] ?? null, InterestEngine::INTERESTED, true) ? 'lead.interested' : null,
            default => null,
        };
    }

    public function dispatch(LeadDomainEvent $event): int
    {
        $name = $this->nameFor($event);

        if ($name === null) {
            return 0;
        }

        $subscriptions = WebhookSubscription::query()->where('tenant_id', $event->tenantId())->where('is_active', true)->whereNull('paused_at')->get()
            ->filter(fn (WebhookSubscription $s) => $s->listensTo($name));

        if ($subscriptions->isEmpty()) {
            return 0;
        }

        $eventId = (string) Str::uuid();
        $payload = $this->payload($name, $eventId, $event);
        $queued = 0;

        foreach ($subscriptions as $subscription) {
            try {
                $delivery = WebhookDelivery::create([
                    'tenant_id' => $subscription->tenant_id,
                    'subscription_id' => $subscription->id,
                    'event' => $name,
                    'event_id' => $eventId,
                    'payload' => $payload,
                ]);
            } catch (UniqueConstraintViolationException) {
                continue;
            }

            DeliverWebhook::dispatch($delivery->id)->afterCommit();
            $queued++;
        }

        return $queued;
    }

    /** A signed test event, so a company can see its endpoint receive one before anything real happens. */
    public function test(WebhookSubscription $subscription): WebhookDelivery
    {
        $eventId = (string) Str::uuid();

        $delivery = WebhookDelivery::create([
            'tenant_id' => $subscription->tenant_id,
            'subscription_id' => $subscription->id,
            'event' => 'webhook.test',
            'event_id' => $eventId,
            'payload' => ['id' => $eventId, 'event' => 'webhook.test', 'occurred_at' => now()->toIso8601String(), 'data' => ['message' => 'Mohtam webhook test']],
        ]);

        DeliverWebhook::dispatchSync($delivery->id);

        return $delivery->fresh();
    }

    public static function acceptableUrl(string $url): bool
    {
        return str_starts_with($url, 'https://') && SafeUrl::isAcceptable($url);
    }

    private function payload(string $name, string $eventId, LeadDomainEvent $event): array
    {
        $lead = $event->lead()->loadMissing(['project:id,uuid,name', 'assignee:id,uuid,name']);
        $context = property_exists($event, 'context') ? $event->context : [];

        $data = ['lead' => $this->lead($lead)];

        if ($event instanceof LeadStageChanged) {
            $data['change'] = ['from' => $event->from->value, 'to' => $event->to->value];
        }

        if ($name === 'sale.created') {
            $data['sale'] = ['value_minor' => $lead->deal_value_minor ?: null, 'won_at' => $lead->won_at?->toIso8601String()];
        }

        if ($name === 'reservation.created' && is_string($context['reservation'] ?? null)) {
            $data['reservation'] = ['id' => $context['reservation']];
        }

        if ($name === 'visit.created' && isset($context['appointment'])) {
            $appointment = $context['appointment'];
            $data['visit'] = is_object($appointment)
                ? ['id' => $appointment->uuid, 'scheduled_at' => $appointment->scheduled_at?->toIso8601String()]
                : ['id' => (string) $appointment];
        }

        if ($name === 'lead.interested') {
            $data['interest'] = ['from' => $context['from'] ?? null, 'to' => $context['to'] ?? null];
        }

        return ['id' => $eventId, 'event' => $name, 'occurred_at' => now()->toIso8601String(), 'data' => $data];
    }

    private function lead(Lead $lead): array
    {
        return [
            'id' => $lead->uuid,
            'full_name' => $lead->full_name,
            'phone' => $lead->phone,
            'email' => $lead->email,
            'status' => $lead->status?->value,
            'source' => $lead->source,
            'campaign' => $lead->campaign_name,
            'project' => $lead->project ? ['id' => $lead->project->uuid, 'name' => $lead->project->name] : null,
            'owner' => $lead->assignee ? ['id' => $lead->assignee->uuid, 'name' => $lead->assignee->name] : null,
        ];
    }
}
