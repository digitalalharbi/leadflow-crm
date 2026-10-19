<?php

namespace App\Jobs;

use App\Enums\ActivityType;
use App\Enums\MessageStatus;
use App\Mail\LeadEmail;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\Email\EmailManager;
use App\Services\Email\TrackedLinks;
use App\Services\Leads\ActivityLogger;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Exception\ExceptionInterface;
use Throwable;

/**
 * Sends one email that is already written down.
 *
 * Idempotent on the row: a message that is no longer pending is left alone, so
 * a job delivered twice — which a queue is allowed to do — sends once. Retried
 * here, with a backoff, only for failures that describe the attempt (a dropped
 * connection, a busy server); an address the server refused is permanent, and
 * trying it three more times only delays telling the rep.
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

            $message->forceFill(['attempts' => $message->attempts + 1])->save();

            try {
                $manager->mailer($integration)->to($lead->email, $lead->full_name)->send(new LeadEmail(
                    mailSubject: (string) $message->subject,
                    htmlBody: $html,
                    plainBody: $message->body."\n\n".$unsubscribe,
                    senderAddress: $from['address'],
                    senderName: $from['name'],
                    replyAddress: $this->replyAddress($token) ?? ($integration?->config['from_address'] ?? null),
                    messageId: $messageId,
                    unsubscribeUrl: $unsubscribe,
                    inReplyTo: $this->threadFor($message),
                ));
            } catch (Throwable $e) {
                $this->failed_($message, $e);

                return;
            }

            $message->forceFill([
                'status' => MessageStatus::Sent,
                'sent_at' => now(),
                'provider_message_id' => $messageId,
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

    private function failed_(LeadMessage $message, Throwable $e): void
    {
        $reason = Str::limit($e->getMessage(), 240);

        if ($this->isPermanent($e) || $message->attempts >= self::MAX_ATTEMPTS) {
            $this->fail_($message, $this->isPermanent($e) ? 'rejected' : 'gave_up', $reason);

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

    /** An SMTP 5xx reply, or an address that is not an address. Not worth another try. */
    private function isPermanent(Throwable $e): bool
    {
        return preg_match('/\b5\d\d[ -]/', $e->getMessage()) === 1
            || $e instanceof ExceptionInterface;
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
