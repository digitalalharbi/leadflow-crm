<?php

namespace App\Domain\Mail\Providers;

use App\Domain\Mail\DeliveryResult;
use App\Domain\Mail\MailerProvider;
use App\Domain\Mail\OutboundEmail;
use Aws\Ses\SesClient;
use Aws\SesV2\SesV2Client;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Amazon SES, through Laravel's `ses` transport. Ready and waiting: it needs the AWS keys, a region
 * and the AWS SDK installed, and says so (`requires()`) until it has them.
 */
final class SesMailer implements MailerProvider
{
    use ShapesMessages;

    public function key(): string
    {
        return 'ses';
    }

    public function isConfigured(): bool
    {
        return $this->requires() === [];
    }

    public function requires(): array
    {
        return array_values(array_filter([
            blank(config('leadflow_mail.ses.key')) ? 'AWS_ACCESS_KEY_ID' : null,
            blank(config('leadflow_mail.ses.secret')) ? 'AWS_SECRET_ACCESS_KEY' : null,
            blank(config('leadflow_mail.ses.region')) ? 'AWS_DEFAULT_REGION' : null,
            ! class_exists(SesV2Client::class) && ! class_exists(SesClient::class) ? 'aws/aws-sdk-php' : null,
        ]));
    }

    public function delivers(): bool
    {
        return true;
    }

    public function send(OutboundEmail $email): DeliveryResult
    {
        if (! $this->isConfigured()) {
            return DeliveryResult::failed('SES is not configured: '.implode(', ', $this->requires()).' missing.', retryable: false);
        }

        try {
            $id = '<'.Str::uuid().'@'.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'mohtam.local').'>';

            Mail::mailer('ses')->html($email->html, fn ($message) => $this->shape($message, $email, $id));

            return DeliveryResult::sent($email->headers['Message-ID'] ?? $id);
        } catch (Throwable $e) {
            // Throttling and server errors are worth another try; a rejected message is not.
            $rejected = str_contains($e->getMessage(), 'MessageRejected') || str_contains($e->getMessage(), 'InvalidParameter') || $this->isPermanent($e);

            return DeliveryResult::failed($e->getMessage(), retryable: ! $rejected);
        }
    }
}
