<?php

namespace App\Domain\Mail\Providers;

use App\Domain\Mail\DeliveryResult;
use App\Domain\Mail\MailerProvider;
use App\Domain\Mail\OutboundEmail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * An SMTP server of the operator's choosing, through Laravel's own smtp transport. Ready, and
 * waiting: it is not configured until a real host and a login are set, so the stock 127.0.0.1 does
 * not count.
 */
final class SmtpMailer implements MailerProvider
{
    use ShapesMessages;

    public function key(): string
    {
        return 'smtp';
    }

    public function isConfigured(): bool
    {
        $host = (string) config('mail.mailers.smtp.host');

        return $host !== '' && ! in_array($host, ['127.0.0.1', 'localhost', '::1'], true) && filled(config('mail.mailers.smtp.username'));
    }

    public function requires(): array
    {
        return array_values(array_filter([
            in_array((string) config('mail.mailers.smtp.host'), ['', '127.0.0.1', 'localhost', '::1'], true) ? 'MAIL_HOST' : null,
            blank(config('mail.mailers.smtp.username')) ? 'MAIL_USERNAME' : null,
            blank(config('mail.mailers.smtp.password')) ? 'MAIL_PASSWORD' : null,
        ]));
    }

    public function delivers(): bool
    {
        return true;
    }

    public function send(OutboundEmail $email): DeliveryResult
    {
        if (! $this->isConfigured()) {
            return DeliveryResult::failed('SMTP is not configured: '.implode(', ', $this->requires()).' missing.', retryable: false);
        }

        try {
            $id = '<'.Str::uuid().'@'.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'mohtam.local').'>';

            Mail::mailer('smtp')->html($email->html, fn ($message) => $this->shape($message, $email, $id));

            return DeliveryResult::sent($email->headers['Message-ID'] ?? $id);
        } catch (Throwable $e) {
            // A refused address will not be accepted on a second try; a connection that dropped may be.
            return DeliveryResult::failed($e->getMessage(), retryable: ! $this->isPermanent($e));
        }
    }
}
