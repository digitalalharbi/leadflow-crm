<?php

namespace App\Domain\Mail\Providers;

use App\Domain\Mail\DeliveryResult;
use App\Domain\Mail\MailerProvider;
use App\Domain\Mail\OutboundEmail;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Resend's HTTP API. Ready and waiting for `RESEND_API_KEY`: the request is built and the answer is
 * read exactly as the API documents (tested against faked responses), and nothing is connected.
 */
final class ResendMailer implements MailerProvider
{
    public function key(): string
    {
        return 'resend';
    }

    public function isConfigured(): bool
    {
        return filled(config('leadflow_mail.resend.key'));
    }

    public function requires(): array
    {
        return $this->isConfigured() ? [] : ['RESEND_API_KEY'];
    }

    public function delivers(): bool
    {
        return true;
    }

    public function send(OutboundEmail $email): DeliveryResult
    {
        if (! $this->isConfigured()) {
            return DeliveryResult::failed('Resend is not configured: RESEND_API_KEY is missing.', retryable: false);
        }

        try {
            $response = Http::withToken((string) config('leadflow_mail.resend.key'))
                ->withHeaders($email->idempotencyKey !== '' ? ['Idempotency-Key' => $email->idempotencyKey] : [])
                ->acceptJson()->timeout(15)
                ->post((string) config('leadflow_mail.resend.endpoint'), array_filter([
                    'from' => $email->fromName !== '' ? "{$email->fromName} <{$email->fromAddress}>" : $email->fromAddress,
                    'to' => [$email->toName ? "{$email->toName} <{$email->toEmail}>" : $email->toEmail],
                    'reply_to' => $email->replyTo,
                    'subject' => $email->subject,
                    'html' => $email->html,
                    'text' => $email->text,
                    // Threading and list headers ride along as given; Resend keeps its own Message-ID.
                    'headers' => array_filter($email->headers, fn ($v, $k) => filled($v) && strtolower($k) !== 'message-id', ARRAY_FILTER_USE_BOTH) ?: null,
                    'tags' => array_map(fn ($k, $v) => ['name' => $k, 'value' => $v], array_keys($email->tags), array_values($email->tags)) ?: null,
                ]));
        } catch (Throwable $e) {
            // The network, not the message: another try may reach it.
            return DeliveryResult::failed($e->getMessage(), retryable: true);
        }

        if ($response->successful() && filled($response->json('id'))) {
            return DeliveryResult::sent((string) $response->json('id'));
        }

        // Throttled or the service is down: wait and try again. Anything else is the message's own fault.
        return DeliveryResult::failed("Resend answered {$response->status()}: ".($response->json('message') ?? $response->body()), retryable: $response->status() === 429 || $response->status() >= 500);
    }
}
