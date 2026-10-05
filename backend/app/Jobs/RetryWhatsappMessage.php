<?php

namespace App\Jobs;

use App\Enums\MessageStatus;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\Whatsapp\FailureClassifier;
use App\Services\Whatsapp\WhatsappManager;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Tries a message again after a failure that might pass.
 *
 * It re-sends the message already on record rather than composing a new one, so
 * the conversation shows one message that eventually arrived — not four
 * attempts the rep has to read past to find out what the customer was actually
 * told.
 */
class RetryWhatsappMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Three attempts over roughly ten minutes, then it stays failed and visible. */
    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public readonly int $messageId) {}

    public function handle(
        WhatsappManager $manager,
        TenantContext $tenants,
        FailureClassifier $classifier,
    ): void {
        /*
         * Both without scopes: a queued job carries no request, so the tenant
         * scope fails closed and the lead comes back null — which this job
         * read as "deleted" and silently declined to retry anything at all.
         */
        $message = LeadMessage::withoutGlobalScopes()->find($this->messageId);
        $lead = $message
            ? Lead::withoutGlobalScopes()->find($message->lead_id)
            : null;

        // Already delivered, or deleted, or a rep retyped it by hand in the
        // meantime: all reasons not to send the customer a second copy.
        if (! $message || $message->status !== MessageStatus::Failed || ! $lead) {
            return;
        }

        $tenants->withTenant($lead->tenant, function () use ($message, $lead, $manager, $classifier) {
            $integration = $manager->integration();

            if (! $manager->canSend($integration)) {
                return;
            }

            $to = $lead->phone_normalized;

            if (! $to) {
                return;
            }

            $result = $manager->providerFor($integration)->sendText($integration, $to, $message->body);

            if ($result->accepted) {
                $message->forceFill([
                    'status' => MessageStatus::Sent,
                    'sent_at' => now(),
                    'failed_at' => null,
                    'failure_code' => null,
                    'failure_reason' => null,
                    'provider_message_id' => $result->providerMessageId,
                ])->save();

                $lead->forceFill([
                    'last_contact_at' => now(),
                    'first_contact_at' => $lead->first_contact_at ?? now(),
                ])->saveQuietly();

                return;
            }

            $message->forceFill([
                'failed_at' => now(),
                'failure_code' => $result->failureCode,
                'failure_reason' => $result->failureReason,
            ])->save();

            // Whatever it failed with this time decides whether there is a next
            // time — a transient failure that turned permanent must stop here.
            if ($classifier->isTransient($result->failureCode) && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 300);
            }
        });
    }
}
