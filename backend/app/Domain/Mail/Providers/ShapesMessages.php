<?php

namespace App\Domain\Mail\Providers;

use App\Domain\Mail\OutboundEmail;
use Illuminate\Mail\Message;
use Symfony\Component\Mime\Exception\ExceptionInterface;
use Throwable;

/**
 * What the providers that ride on a Laravel transport have in common: putting an OutboundEmail onto
 * a Symfony message — addresses, reply-to, the plain-text twin, the threading and list headers — and
 * telling a failure that will not go away from one that might.
 */
trait ShapesMessages
{
    private function shape(Message $message, OutboundEmail $email, string $fallbackId): void
    {
        $message->to($email->toEmail, $email->toName)->subject($email->subject);

        if ($email->fromAddress !== '') {
            $message->from($email->fromAddress, $email->fromName);
        }

        if (filled($email->replyTo)) {
            $message->replyTo($email->replyTo);
        }

        $symfony = $message->getSymfonyMessage();

        if ($email->text !== null) {
            $symfony->text($email->text);
        }

        $headers = $symfony->getHeaders();
        $named = [];

        foreach ($email->headers as $name => $value) {
            if (blank($value)) {
                continue;
            }

            $named[strtolower($name)] = true;
            in_array(strtolower($name), OutboundEmail::ID_HEADERS, true)
                ? $headers->addIdHeader($name, trim((string) $value, '<> '))
                : $headers->addTextHeader($name, (string) $value);
        }

        // A Message-ID we chose, so a retry that crashed after sending can be recognised rather than
        // sent twice under a different identity.
        if (! isset($named['message-id'])) {
            $headers->addIdHeader('Message-ID', trim($fallbackId, '<> '));
        }
    }

    /** An SMTP 5xx reply, an address that is not an address, or a server that said no: not worth another try. */
    private function isPermanent(Throwable $e): bool
    {
        $text = $e->getMessage();

        return $e instanceof ExceptionInterface
            || preg_match('/\b5\d\d[ -]/', $text) === 1
            || str_contains(strtolower($text), 'rejected');
    }
}
