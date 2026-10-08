<?php

namespace App\Jobs;

use App\Enums\MessageStatus;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\Whatsapp\SendFailure;
use App\Services\Whatsapp\WhatsappManager;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends again, for the failures that describe the attempt rather than the
 * message.
 *
 * A send was one attempt and nothing more. If the HTTP call threw — a dropped
 * connection, Meta returning a 503 — the message was marked failed for good,
 * and whether the customer ever heard from the business depended on a rep
 * noticing a red mark and pressing send again. The conversion exporter in this
 * codebase already retries with a backoff; the channel a rep actually talks to
 * customers on did not.
 *
 * Deliberately a separate job rather than a retry loop inside the sender: the
 * rep's own request must return immediately with what happened, and waiting out
 * a backoff inside it would hold the browser for five minutes.
 */
class RetryWhatsappMessage implements ShouldQueue
{
    use Queueable;

    /**
     * Three in total, counting the one the rep made.
     *
     * Past that it is not a blip, and a message still bouncing after half an
     * hour is something the rep should be told about rather than kept waiting
     * on.
     */
    public const MAX_ATTEMPTS = 3;

    public function __construct(public int $messageId) {}

    public function handle(WhatsappManager $manager, TenantContext $tenants): void
    {
        $message = LeadMessage::withoutGlobalScopes()->find($this->messageId);

        // Nothing to do if it was sent in the meantime, or was given up on.
        if (! $message || $message->status !== MessageStatus::Pending) {
            return;
        }

        /*
         * The lead is loaded without scopes too, and that is not a detail.
         *
         * A queue worker has no request, the tenant scope fails closed outside
         * one, and `$message->lead` therefore came back null — so the guard
         * below would have swallowed every retry in production while every test
         * that set a tenant context passed. The scope is lifted here and the
         * tenant is established from the lead a line later.
         */
        $lead = Lead::withoutGlobalScopes()->find($message->lead_id);

        if (! $lead) {
            return;
        }

        /*
         * No tenant context: this runs from the queue, where there is no
         * request to resolve one from, and the scope fails closed — every
         * lookup below would quietly find nothing.
         */
        $tenants->withTenant($lead->tenant, function () use ($manager, $message, $lead) {
            $integration = $manager->integration();

            if (! $manager->canSend($integration)) {
                $this->giveUp($message, 'not_connected', 'WhatsApp is no longer connected for this workspace.');

                return;
            }

            /*
             * Checked again, not assumed from the first attempt. A customer who
             * replied STOP between the failed send and this retry must not be
             * messaged because a job was already in flight.
             */
            if (! $lead->acceptsWhatsapp()) {
                $this->giveUp($message, 'opted_out', 'This lead asked not to receive WhatsApp messages.');

                return;
            }

            $to = $lead->phone_normalized ?: (string) $lead->phone;
            $result = $manager->providerFor($integration)->sendText($integration, $to, (string) $message->body);

            $message->forceFill(['attempts' => $message->attempts + 1]);

            if ($result->accepted) {
                $message->forceFill([
                    'status' => MessageStatus::Sent,
                    'sent_at' => now(),
                    'provider_message_id' => $result->providerMessageId,
                    'failure_code' => null,
                    'failure_reason' => null,
                ])->save();

                $lead->forceFill([
                    'last_contact_at' => now(),
                    'first_contact_at' => $lead->first_contact_at ?? now(),
                ])->saveQuietly();

                return;
            }

            $canRetry = SendFailure::isTransient($result->failureCode)
                && $message->attempts < self::MAX_ATTEMPTS;

            if (! $canRetry) {
                $this->giveUp($message, $result->failureCode, $result->failureReason);

                return;
            }

            $message->save();

            self::dispatch($message->id)->delay(self::delayFor($message->attempts));
        });
    }

    /** A minute, then five. Long enough to outlast a blip, short enough to matter. */
    public static function delayFor(int $attempts): int
    {
        return [1 => 60, 2 => 300][$attempts] ?? 300;
    }

    private function giveUp(LeadMessage $message, ?string $code, ?string $reason): void
    {
        $message->forceFill([
            'status' => MessageStatus::Failed,
            'failed_at' => now(),
            'failure_code' => $code,
            'failure_reason' => $reason,
        ])->save();
    }
}
