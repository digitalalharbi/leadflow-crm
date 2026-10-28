<?php

namespace App\Domain\Mail;

use App\Jobs\SendQueuedEmail;
use App\Models\EmailDelivery;
use App\Models\EmailDeliveryAttempt;
use App\Models\User;
use App\Support\Brand;
use Illuminate\Support\Facades\DB;

/**
 * The queue, the retries, the failure log and the delivery status of the product's own email.
 *
 * `queue()` writes the email down first (so there is always a record) and hands it to the queue; the
 * job calls `attempt()`, which asks the provider once, records the answer, and decides what happens
 * next: sent, logged, wait and try again on the backoff schedule, or failed for good. Asking to queue
 * the same message twice (same idempotency key) is one email; `attempt()` on a delivery that is not
 * waiting does nothing, so a duplicated job, a double click or two workers cannot send it twice.
 */
class EmailDeliveries
{
    public function __construct(private MailerRegistry $registry) {}

    /**
     * @param  array{kind?:string,subject_type?:?string,subject_id?:?int,created_by?:?int}  $meta
     */
    public function queue(OutboundEmail $email, array $meta = [], ?User $by = null): EmailDelivery
    {
        $key = $email->idempotencyKey !== '' ? $email->idempotencyKey : null;

        if ($key !== null && ($existing = EmailDelivery::query()->where('idempotency_key', $key)->first())) {
            return $existing;
        }

        $delivery = EmailDelivery::create([
            'kind' => $meta['kind'] ?? 'report',
            'to_email' => $email->toEmail, 'to_name' => $email->toName, 'subject' => Brand::subject($email->subject), 'html' => $email->html, 'text' => $email->text,
            'provider' => $this->registry->current()->key(),
            'status' => EmailDelivery::QUEUED,
            'max_attempts' => count((array) config('leadflow_mail.backoff', [])) + 1,
            'subject_type' => $meta['subject_type'] ?? null, 'subject_id' => $meta['subject_id'] ?? null,
            'idempotency_key' => $key, 'created_by' => $by?->id ?? ($meta['created_by'] ?? null),
        ]);

        SendQueuedEmail::dispatch($delivery->id)->onQueue((string) config('leadflow_mail.queue', 'default'))->afterCommit();

        return $delivery;
    }

    /** One try. Safe to call twice: only a waiting delivery is attempted. */
    public function attempt(int $deliveryId): ?EmailDelivery
    {
        $delivery = DB::transaction(function () use ($deliveryId) {
            $row = EmailDelivery::query()->whereKey($deliveryId)->lockForUpdate()->first();

            if ($row === null || $row->status !== EmailDelivery::QUEUED) {
                return null;
            }

            $row->update(['status' => EmailDelivery::SENDING]);

            return $row;
        });

        if ($delivery === null) {
            return null;
        }

        // The provider that was chosen when it was queued; a delivery does not change door halfway.
        $provider = $this->registry->get($delivery->provider);
        $result = $provider->send($this->outbound($delivery));
        $n = $delivery->attempts + 1;

        EmailDeliveryAttempt::create([
            'tenant_id' => $delivery->tenant_id, 'email_delivery_id' => $delivery->id, 'attempt' => $n, 'provider' => $provider->key(),
            'ok' => $result->ok, 'retryable' => $result->retryable, 'error' => $result->error, 'provider_message_id' => $result->messageId, 'attempted_at' => now(),
        ]);

        if ($result->ok) {
            $delivery->update([
                'attempts' => $n, 'status' => $result->simulated ? EmailDelivery::LOGGED : EmailDelivery::SENT, 'provider_message_id' => $result->messageId,
                'last_error' => null, 'next_attempt_at' => null, 'sent_at' => now(),
            ]);

            return $delivery->fresh();
        }

        $backoff = (array) config('leadflow_mail.backoff', []);

        if ($result->retryable && $n < $delivery->max_attempts) {
            $wait = (int) ($backoff[$n - 1] ?? end($backoff) ?: 60);
            $delivery->update(['attempts' => $n, 'status' => EmailDelivery::QUEUED, 'last_error' => $result->error, 'next_attempt_at' => now()->addSeconds($wait)]);
            SendQueuedEmail::dispatch($delivery->id)->onQueue((string) config('leadflow_mail.queue', 'default'))->delay(now()->addSeconds($wait));

            return $delivery->fresh();
        }

        $delivery->update(['attempts' => $n, 'status' => EmailDelivery::FAILED, 'last_error' => $result->error, 'next_attempt_at' => null, 'failed_at' => now()]);

        return $delivery->fresh();
    }

    /** A person asks for a failed email to be tried again: a fresh run of attempts, on the provider in force now. */
    public function retry(EmailDelivery $delivery): EmailDelivery
    {
        if ($delivery->status !== EmailDelivery::FAILED) {
            return $delivery;
        }

        $delivery->update([
            'status' => EmailDelivery::QUEUED, 'provider' => $this->registry->current()->key(), 'attempts' => 0, 'last_error' => null, 'failed_at' => null,
            'max_attempts' => count((array) config('leadflow_mail.backoff', [])) + 1,
        ]);
        SendQueuedEmail::dispatch($delivery->id)->onQueue((string) config('leadflow_mail.queue', 'default'))->afterCommit();

        return $delivery->fresh();
    }

    private function outbound(EmailDelivery $d): OutboundEmail
    {
        return new OutboundEmail(
            $d->to_email, $d->to_name, $d->subject, (string) $d->html, $d->text,
            (string) config('leadflow_mail.from.address'), (string) config('leadflow_mail.from.name'),
            // Stable across every attempt of this delivery.
            $d->uuid, ['kind' => $d->kind],
        );
    }
}
