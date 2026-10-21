<?php

namespace App\Domain\Mail\Providers;

use App\Domain\Mail\DeliveryResult;
use App\Domain\Mail\MailerProvider;
use App\Domain\Mail\OutboundEmail;
use Aws\Ses\SesClient;
use Aws\SesV2\SesV2Client;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Amazon SES, through Laravel's `ses` transport. Ready and waiting: it needs the AWS keys, a region
 * and the AWS SDK installed, and says so (`requires()`) until it has them.
 */
final class SesMailer implements MailerProvider
{
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
            $id = null;

            Mail::mailer('ses')->html($email->html, function ($message) use ($email, &$id) {
                $message->to($email->toEmail, $email->toName)->subject($email->subject)->from($email->fromAddress, $email->fromName);
                $id = $message->getSymfonyMessage()->generateMessageId();
            });

            return DeliveryResult::sent($id);
        } catch (Throwable $e) {
            // Throttling and server errors are worth another try; a rejected message is not.
            $rejected = str_contains($e->getMessage(), 'MessageRejected') || str_contains($e->getMessage(), 'InvalidParameter');

            return DeliveryResult::failed($e->getMessage(), retryable: ! $rejected);
        }
    }
}
