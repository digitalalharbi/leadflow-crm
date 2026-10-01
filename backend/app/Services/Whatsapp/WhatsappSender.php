<?php

namespace App\Services\Whatsapp;

use App\Enums\ActivityType;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\User;
use App\Services\Leads\ActivityLogger;
use App\Services\Leads\PhoneNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sends a WhatsApp message on behalf of a rep and records what happened.
 *
 * Every send goes through here, so there is exactly one place that knows the
 * rules: a lead who opted out is never messaged, the message is persisted
 * before we call the provider, and the lead's first-contact timestamp is set
 * the first time anyone actually reaches them.
 */
class WhatsappSender
{
    public function __construct(
        private WhatsappManager $manager,
        private PhoneNormalizer $phones,
        private ActivityLogger $activities,
    ) {}

    /**
     * @throws RuntimeException when the workspace or the lead cannot be messaged
     */
    public function send(Lead $lead, string $body, ?User $actor = null, ?string $templateName = null): LeadMessage
    {
        $integration = $this->manager->integration();

        if (! $this->manager->canSend($integration)) {
            throw new RuntimeException('WhatsApp is not connected for this workspace.');
        }

        if (! $lead->acceptsWhatsapp()) {
            throw new RuntimeException('This lead has asked not to receive WhatsApp messages.');
        }

        /*
         * Per conversation, not per user: the global API limiter would let one
         * rep send a message a second to the same person, which is how a
         * tenant's WhatsApp number gets reported — and a reported number ends
         * the channel for them, not for us.
         */
        $perMinute = (int) config('integrations.whatsapp.messages_per_lead_per_minute');

        if ($perMinute > 0 && RateLimiter::tooManyAttempts($key = 'wa-send:'.$lead->id, $perMinute)) {
            throw new RuntimeException('Too many messages to this lead. Wait a moment before sending again.');
        }

        $to = $lead->phone_normalized ?: $this->phones->normalize((string) $lead->phone);

        if (! $to) {
            throw new RuntimeException('This lead has no usable phone number.');
        }

        if ($perMinute > 0) {
            RateLimiter::hit($key, 60);
        }

        $provider = $this->manager->providerFor($integration);

        /*
         * Written before the call, not after. If the provider accepts the
         * message and we then fail to record it, the customer has been
         * contacted and the CRM says otherwise — the one inconsistency that
         * matters here.
         */
        $message = LeadMessage::create([
            'lead_id' => $lead->id,
            'user_id' => $actor?->id,
            'direction' => MessageDirection::Outbound,
            'channel' => MessageChannel::Whatsapp,
            'provider' => $provider->key(),
            'message_type' => $templateName ? 'template' : 'text',
            'template_name' => $templateName,
            'body' => $body,
            'status' => MessageStatus::Pending,
        ]);

        $result = $provider->sendText($integration, $to, $body);

        if (! $result->accepted) {
            $message->forceFill([
                'status' => MessageStatus::Failed,
                'failed_at' => now(),
                'failure_code' => $result->failureCode,
                'failure_reason' => $result->failureReason,
                'provider_payload' => $result->payload ?: null,
            ])->save();

            return $message;
        }

        DB::transaction(function () use ($lead, $message, $result, $actor, $body) {
            $message->forceFill([
                'status' => MessageStatus::Sent,
                'sent_at' => now(),
                'provider_message_id' => $result->providerMessageId,
                'provider_payload' => $result->payload ?: null,
            ])->save();

            $lead->forceFill([
                'last_contact_at' => now(),
                'first_contact_at' => $lead->first_contact_at ?? now(),
            ])->saveQuietly();

            $this->activities->log(
                $lead,
                ActivityType::WhatsappSent,
                $actor,
                Str::limit($body, 140),
                ['message_uuid' => $message->uuid],
            );
        });

        return $message->refresh();
    }
}
