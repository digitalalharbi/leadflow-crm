<?php

namespace App\Domain\Mail;

use App\Domain\Automation\Recovery;
use App\Domain\Privacy\SuppressionList;
use App\Enums\ActivityType;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\EmailDelivery;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\Leads\ActivityLogger;
use App\Support\TenantContext;
use Illuminate\Support\Str;

/**
 * What an email that could not be delivered does to the lead it was sent to, wherever the news came from: a provider's
 * webhook or a bounce read from the mailbox. The address is marked dead so nothing automatic is sent to it again, and
 * the journey moves on (the same message on WhatsApp, otherwise a person).
 */
class BounceRecorder
{
    public function __construct(private readonly TenantContext $context, private readonly ActivityLogger $activities) {}

    public function markDelivery(?string $email, string $type): void
    {
        $email = mb_strtolower(trim((string) $email));

        if ($email === '') {
            return;
        }

        EmailDelivery::withoutGlobalScopes()->where('to_email', $email)->where('status', EmailDelivery::SENT)->where('updated_at', '>=', now()->subDays(3))
            ->latest('id')->first()?->forceFill(['status' => $type === 'delivered' ? EmailDelivery::DELIVERED : EmailDelivery::BOUNCED])->save();
    }

    /** @return string 'bounces' when a lead was found and stopped, otherwise 'unmatched' */
    public function record(string $email, string $reason = ''): string
    {
        $email = mb_strtolower(trim($email));

        if ($email === '') {
            return 'unmatched';
        }

        // The most recent email we sent to that address — the one that bounced.
        $message = LeadMessage::withoutGlobalScopes()
            ->where('channel', MessageChannel::Email->value)
            ->where('direction', MessageDirection::Outbound->value)
            ->whereIn('lead_id', Lead::withoutGlobalScopes()->whereRaw('lower(email) = ?', [$email])->select('id'))
            ->latest('id')
            ->first();

        if (! $message) {
            return 'unmatched';
        }

        $lead = Lead::withoutGlobalScopes()->find($message->lead_id);

        return $this->context->withTenant($lead->tenant, function () use ($lead, $message, $reason) {
            $message->forceFill([
                'status' => MessageStatus::Failed,
                'failed_at' => now(),
                'failure_code' => 'bounced',
                'failure_reason' => Str::limit($reason !== '' ? $reason : 'Bounced', 250, ''),
            ])->save();

            $lead->forceFill(['email_bounced_at' => now()])->saveQuietly();

            if ($lead->email) {
                app(SuppressionList::class)->add($lead->tenant_id, 'email', $lead->email, 'bounced', null, Str::limit($reason, 200, ''));
            }
            $this->activities->log($lead, ActivityType::EmailBounced, null, Str::limit($reason, 140));

            // An automated email that bounced: the same message on WhatsApp, else a person.
            app(Recovery::class)->afterDeliveryFailure($message->refresh());

            return 'bounces';
        });
    }
}
