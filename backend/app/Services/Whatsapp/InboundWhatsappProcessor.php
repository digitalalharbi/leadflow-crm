<?php

namespace App\Services\Whatsapp;

use App\Domain\Automation\Recovery;
use App\Domain\Privacy\SuppressionList;
use App\Enums\ActivityType;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Events\LeadReplied;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\WhatsappEvent;
use App\Services\Leads\ActivityLogger;
use App\Services\Leads\LeadScoringService;
use App\Services\Leads\PhoneNormalizer;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns a Cloud API webhook delivery into messages and status updates.
 *
 * Three things this has to get right, because none of them are recoverable:
 *  - the delivery belongs to exactly one tenant, decided by the phone number id
 *    it arrived on, never by anything the caller asserts about itself;
 *  - a repeated delivery changes nothing (Meta retries until it sees a 200);
 *  - a message from a number we do not recognise does not invent a customer.
 */
class InboundWhatsappProcessor
{
    public function __construct(
        private TenantContext $context,
        private PhoneNormalizer $phones,
        private ActivityLogger $activities,
        private LeadScoringService $scoring,
    ) {}

    /**
     * @return array{messages: int, statuses: int, unmatched: int, duplicates: int}
     */
    public function process(Integration $integration, array $payload): array
    {
        $counts = ['messages' => 0, 'statuses' => 0, 'unmatched' => 0, 'duplicates' => 0];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                // The number the delivery arrived on must be the one this
                // integration owns, even though we already matched on it — a
                // batched payload can carry entries for several numbers.
                $phoneNumberId = (string) ($value['metadata']['phone_number_id'] ?? '');

                if ($phoneNumberId !== (string) ($integration->config['phone_number_id'] ?? '')) {
                    $this->record($integration, WhatsappEvent::TYPE_REJECTED, $phoneNumberId ?: Str::uuid()->toString(), [
                        'outcome' => 'phone_number_mismatch',
                        'phone_number_id' => $phoneNumberId,
                    ]);

                    continue;
                }

                foreach ($value['messages'] ?? [] as $message) {
                    $outcome = $this->handleMessage($integration, $message, $value);
                    $counts[$outcome]++;
                }

                foreach ($value['statuses'] ?? [] as $status) {
                    $outcome = $this->handleStatus($integration, $status);
                    $counts[$outcome]++;
                }
            }
        }

        return $counts;
    }

    /** @return 'messages'|'unmatched'|'duplicates' */
    private function handleMessage(Integration $integration, array $message, array $value): string
    {
        $providerId = (string) ($message['id'] ?? '');

        if ($providerId === '') {
            return 'duplicates';
        }

        /*
         * The claim and the write live in one transaction.
         *
         * Claiming first and writing afterwards leaves a window where a failure
         * between them answers Meta's retry with "already seen" and loses the
         * customer's message for good. Inside the transaction, a rollback
         * releases the claim with it, and the retry is handled properly.
         */
        $outcome = null;

        DB::transaction(function () use ($integration, $message, $providerId, $value, &$outcome) {
            if (! $this->claim($integration, WhatsappEvent::TYPE_MESSAGE, $providerId, [
                'from_phone' => $message['from'] ?? null,
                'payload' => $message,
            ])) {
                $outcome = 'duplicates';

                return;
            }

            $outcome = $this->attach($integration, $message, $providerId, $value);
        });

        return $outcome ?? 'duplicates';
    }

    /** @return 'messages'|'unmatched' */
    private function attach(Integration $integration, array $message, string $providerId, array $value): string
    {
        $from = $this->phones->normalize((string) ($message['from'] ?? ''));
        $lead = $from ? Lead::where('phone_normalized', $from)->first() : null;

        if (! $lead) {
            /*
             * Deliberately not creating a lead. A message from an unknown number
             * is as likely to be a wrong number or a supplier as a customer, and
             * a CRM that invents records from strangers stops being trusted. The
             * event is kept so someone can look.
             */
            $this->record($integration, WhatsappEvent::TYPE_UNMATCHED, $providerId, [
                'from_phone' => $message['from'] ?? null,
                'outcome' => 'no_matching_lead',
                'payload' => $message,
            ]);

            return 'unmatched';
        }

        $body = $this->extractBody($message);

        /*
         * A reply, as opposed to a second message in a row: the last thing in
         * the thread was ours (or there is nothing yet). Only a reply moves
         * the lead — raises its score, hands it to a person — so a customer
         * who sends five messages is not scored five times.
         */
        $last = LeadMessage::where('lead_id', $lead->id)->latest('id')->value('direction');
        $isReply = $last === null || $last === MessageDirection::Outbound || $last === MessageDirection::Outbound->value;

        LeadMessage::create([
            'lead_id' => $lead->id,
            'direction' => MessageDirection::Inbound,
            'channel' => MessageChannel::Whatsapp,
            'provider' => 'whatsapp_cloud',
            'provider_message_id' => $providerId,
            'message_type' => (string) ($message['type'] ?? 'text'),
            'body' => $body,
            'status' => MessageStatus::Received,
            'provider_payload' => ['message' => $message, 'contacts' => $value['contacts'] ?? null],
        ]);

        /*
         * A reply is consent in the plainest sense, and it is also the
         * engagement signal the score depends on. Both are recorded the
         * first time only — re-stamping them on every reply would make the
         * response-time report meaningless.
         */
        $lead->first_response_at ??= now();
        $lead->whatsapp_opt_in_at ??= now();
        $lead->last_contact_at = now();
        $lead->first_contact_at ??= now();

        $before = $lead->score;
        $this->scoring->apply($lead);
        $lead->saveQuietly();

        $this->activities->log($lead, ActivityType::WhatsappReceived, null, Str::limit($body ?? '', 140));

        // "إيقاف" / "STOP" on its own: no more automated messages to this number, from any lead that carries it.
        if ($lead->whatsapp_opt_out_at === null && app(SuppressionList::class)->isStopMessage($body)) {
            $lead->forceFill(['whatsapp_opt_out_at' => now()])->saveQuietly();
            app(SuppressionList::class)->add($lead->tenant_id, 'phone', (string) ($lead->phone_normalized ?? $lead->phone), 'opt_out', null, 'whatsapp');
            $this->activities->log($lead, ActivityType::WhatsappOptedOut, null, 'طلب إيقاف الرسائل عبر واتساب');
        }

        if ($isReply) {
            $lead->stopWaitingForReply();
            LeadReplied::dispatch($lead, null, ['channel' => 'whatsapp']);
        }

        if ($before !== $lead->score) {
            $this->activities->log($lead, ActivityType::ScoreChanged, null, null, [
                'from' => $before,
                'to' => $lead->score,
                'reason' => 'whatsapp_reply',
            ]);
        }

        return 'messages';
    }

    /** @return 'statuses'|'duplicates' */
    private function handleStatus(Integration $integration, array $status): string
    {
        $providerId = (string) ($status['id'] ?? '');
        $state = (string) ($status['status'] ?? '');

        if ($providerId === '' || $state === '') {
            return 'duplicates';
        }

        // One claim per (message, state): "delivered" and "read" for the same
        // message are different events, the same one twice is not.
        if (! $this->claim($integration, WhatsappEvent::TYPE_STATUS, $providerId.':'.$state, ['payload' => $status])) {
            return 'duplicates';
        }

        /*
         * Outbound only. A receipt describes the delivery of something we sent;
         * an id can repeat across providers within a tenant, and without this
         * filter a crafted `statuses` entry could land on a message the
         * customer sent us — whose failure text is rendered straight back to
         * the tenant.
         */
        $message = LeadMessage::query()
            ->outbound()
            ->where('provider_message_id', $providerId)
            ->orderBy('id')
            ->first();

        if (! $message) {
            return 'duplicates';
        }

        $mapped = match ($state) {
            'sent' => MessageStatus::Sent,
            'delivered' => MessageStatus::Delivered,
            'read' => MessageStatus::Read,
            'failed' => MessageStatus::Failed,
            default => null,
        };

        if ($mapped === null || $message->status->isTerminal()) {
            return 'duplicates';
        }

        $at = isset($status['timestamp']) ? now()->setTimestamp((int) $status['timestamp']) : now();

        // Written only once the status is allowed to move, and truncated: the
        // reason is provider text we store and show, not a free column.
        if ($mapped === MessageStatus::Failed) {
            $error = $status['errors'][0] ?? [];

            $message->forceFill([
                'failure_code' => Str::limit((string) ($error['code'] ?? 'unknown'), 60, ''),
                'failure_reason' => Str::limit(
                    (string) ($error['title'] ?? $error['message'] ?? 'Delivery failed.'),
                    400,
                ),
            ])->save();
        }

        $message->advanceStatus($mapped, $at);

        // An automated message that did not arrive: the other channel, else a person.
        if ($mapped === MessageStatus::Failed) {
            app(Recovery::class)->afterDeliveryFailure($message->refresh());
        }

        return 'statuses';
    }

    /**
     * Take ownership of an event id, or report that someone already has.
     *
     * The unique index decides, not a prior SELECT: two concurrent retries of
     * the same delivery would both pass a check-then-insert. Written as ON
     * CONFLICT DO NOTHING rather than a caught exception because in Postgres a
     * unique violation aborts the surrounding transaction — and a repeated
     * webhook is an ordinary event here, not an error.
     */
    private function claim(Integration $integration, string $type, string $eventId, array $extra = []): bool
    {
        return $this->record($integration, $type, $eventId, $extra) === 1;
    }

    /** @return int rows written; 0 means this delivery was already recorded */
    private function record(Integration $integration, string $type, string $eventId, array $extra = []): int
    {
        return DB::table('whatsapp_events')->insertOrIgnore([
            'tenant_id' => $integration->tenant_id,
            'integration_id' => $integration->id,
            'event_type' => $type,
            'event_id' => $eventId,
            'phone_number_id' => $integration->config['phone_number_id'] ?? null,
            'from_phone' => $extra['from_phone'] ?? null,
            'outcome' => $extra['outcome'] ?? null,
            'detail' => $extra['detail'] ?? null,
            'payload' => isset($extra['payload']) ? json_encode($extra['payload'], JSON_UNESCAPED_UNICODE) : null,
            'created_at' => now(),
        ]);
    }

    /** Text, or a readable stand-in for the media types we do not render. */
    private function extractBody(array $message): ?string
    {
        return match ((string) ($message['type'] ?? 'text')) {
            'text' => $message['text']['body'] ?? null,
            'button' => $message['button']['text'] ?? null,
            'interactive' => $message['interactive']['button_reply']['title']
                ?? $message['interactive']['list_reply']['title']
                ?? null,
            'image' => '[صورة]',
            'video' => '[مقطع فيديو]',
            'audio' => '[رسالة صوتية]',
            'document' => '[مستند]',
            'location' => '[موقع]',
            default => '[رسالة غير مدعومة]',
        };
    }
}
