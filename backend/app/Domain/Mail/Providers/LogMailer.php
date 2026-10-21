<?php

namespace App\Domain\Mail\Providers;

use App\Domain\Mail\DeliveryResult;
use App\Domain\Mail\MailerProvider;
use App\Domain\Mail\OutboundEmail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Writes the email to the log and sends nothing — the default, and the only one that needs no keys.
 * It reports `logged`, never `sent`: an email written to a file is not a person who heard back.
 */
final class LogMailer implements MailerProvider
{
    public function key(): string
    {
        return 'log';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function requires(): array
    {
        return [];
    }

    public function delivers(): bool
    {
        return false;
    }

    public function send(OutboundEmail $email): DeliveryResult
    {
        $id = 'log-'.Str::uuid();

        Log::info('email.logged', ['id' => $id, 'to' => $email->toEmail, 'subject' => $email->subject, 'tags' => $email->tags]);

        return DeliveryResult::logged($id);
    }
}
