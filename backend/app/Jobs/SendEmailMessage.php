<?php

namespace App\Jobs;

use App\Domain\Mail\DeliveryResult;
use App\Domain\Mail\OutboundEmail;
use App\Enums\ActivityType;
use App\Enums\MessageStatus;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\Email\EmailManager;
use App\Services\Email\TrackedLinks;
use App\Services\Leads\ActivityLogger;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Sends one email that is already written down.
 *
 * Idempotent on the row: a message that is no longer pending is left alone, so
 * a job delivered twice — which a queue is allowed to do — sends once. Retried
 * here, with a backoff, only for failures that describe the attempt (a dropped
 * connection, a busy server); an address the server refused is permanent, and
 * trying it three more times only delays telling the rep.
 *
 * The door is a MailerProvider — the workspace's own server or the product's
 * provider — and never Laravel's default mailer.
 */
class SendEmailMessage implements ShouldQueue
{
    use Queueable;

    public const MAX_ATTEMPTS = 3;

    public function __construct(public int $messageId) {}

    public static function delayFor(int $attempt): int
    {
        return [1 => 60, 2 => 300][$attempt] ?? 900;
    }

    public function handle(EmailManager $manager, TrackedLinks $links, ActivityLogger $activities, TenantContext $tenants): void
    {
        $message = LeadMessage::withoutGlobalScopes()->find($this->messageId);

        if (! $message || $message->status !== MessageStatus::Pending) {
            return;
        }

        // Lifted for the same reason the WhatsApp retry lifts it: a worker has
        // no request, and the tenant scope fails closed outside one.
        $lead = Lead::withoutGlobalScopes()->find($message->lead_id);

        if (! $lead) {
            return;
        }

        $tenants->withTenant($lead->tenant, function () use ($manager, $links, $activities, $message, $lead) {
            // Checked again, not assumed from when it was queued: an unsubscribe
            // between the click and the send must win.
            if (! $lead->acceptsEmail()) {
                $this->fail_($message, 'opted_out', 'This lead can no longer be emailed.');

                return;
            }

            $integration = $manager->integration();
            $company = $lead->tenant?->branding?->company_name ?: $lead->tenant?->name;
            $from = $manager->from($integration, $company);
            $token = (string) $message->tracking_token;
            $messageId = Str::uuid()->toString().'@'.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');

            $html = $links->toHtml((string) $message->body, $token)
                .'<br><br><img src="'.e(route('email.open', ['token' => $token])).'" width="1" height="1" alt="" style="display:none">';

            $unsubscribe = route('email.unsubscribe', ['token' => $token]);
            $html .= '<p style="color:#888;font-size:12px"><a href="'.e($unsubscribe).'">إلغاء الاشتراك</a></p>';
            $thread = $this->threadFor($message);

            $message->forceFill(['attempts' => $message->attempts + 1])->save();

            $result = $manager->door($integration)->send(new OutboundEmail(
                $lead->email, $lead->full_name, (string) $message->subject, $html,
                $message->body."\n\n".$unsubscribe,
                $from['address'], $from['name'],
                $messageId, ['kind' => 'lead_message'],
                // With nowhere of its own to answer to, a reply goes to the mailbox the product reads, never to a dead address.
                replyTo: $this->replyAddress($token) ?? ($integration?->config['from_address'] ?? (config('leadflow_mail.reply_to') ?: null)),
                headers: array_filter([
                    'Message-ID' => $messageId,
                    // The thread: the customer's client shows this under the email it answers.
                    'In-Reply-To' => $thread,
                    'References' => $thread,
                    // A one-click unsubscribe the mail client can offer by itself.
                    'List-Unsubscribe' => '<'.$unsubscribe.'>',
                    'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                ]),
            ));

            if (! $result->ok) {
                $this->failed_($message, $result);

                return;
            }

            $message->forceFill([
                'status' => MessageStatus::Sent,
                'sent_at' => now(),
                // The Message-ID the reader's client saw, which is ours unless the provider minted a real one.
                'provider_message_id' => $result->messageId !== null && str_contains($result->messageId, '@') ? trim($result->messageId, '<>') : $messageId,
                'failure_code' => null,
                'failure_reason' => null,
            ])->save();

            $lead->forceFill([
                'last_contact_at' => now(),
                'first_contact_at' => $lead->first_contact_at ?? now(),
            ])->saveQuietly();

            $activities->log(
                $lead,
                ActivityType::EmailSent,
                $message->user_id ? $message->user : null,
                Str::limit((string) $message->subject, 140),
                ['message_uuid' => $message->uuid],
            );
        });
    }

    private function failed_(LeadMessage $message, DeliveryResult $result): void
    {
        $reason = Str::limit((string) $result->error, 240);

        if (! $result->retryable || $message->attempts >= self::MAX_ATTEMPTS) {
            $this->fail_($message, ! $result->retryable ? 'rejected' : 'gave_up', $reason);

            return;
        }

        // Still pending, with the reason on it, and another go on the way.
        $message->forceFill(['failure_code' => 'transport_error', 'failure_reason' => $reason])->save();

        self::dispatch($message->id)->delay(self::delayFor($message->attempts));
    }

    private function fail_(LeadMessage $message, string $code, string $reason): void
    {
        $message->forceFill([
            'status' => MessageStatus::Failed,
            'failed_at' => now(),
            'failure_code' => $code,
            'failure_reason' => $reason,
        ])->save();
    }

    /**
     * Where the customer's reply goes: to this message's own address on the
     * inbound domain, so it is matched to this lead without guessing.
     */
    private function replyAddress(string $token): ?string
    {
        $domain = config('services.email_inbound.domain');

        return filled($domain) && $token !== '' ? "reply+{$token}@{$domain}" : null;
    }

    /** The last email in this lead's conversation, to thread this one under. */
    private function threadFor(LeadMessage $message): ?string
    {
        $previous = LeadMessage::withoutGlobalScopes()
            ->where('lead_id', $message->lead_id)
            ->where('channel', 'email')
            ->where('id', '!=', $message->id)
            ->whereNotNull('provider_message_id')
            ->latest('id')
            ->value('provider_message_id');

        return $previous ? trim((string) $previous, '<> ') : null;
    }
}
