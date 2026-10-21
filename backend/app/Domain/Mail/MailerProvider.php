<?php

namespace App\Domain\Mail;

/**
 * One way out for an email. Everything above this — the queue, the retries, the delivery log, the
 * screens — talks to this interface and never to a provider, so adding one is writing one class.
 *
 * A provider says honestly what it is: `isConfigured()` is false until it has what it needs, and
 * `send()` on an unconfigured provider fails without retrying (a retry cannot supply a missing key).
 */
interface MailerProvider
{
    /** The name it goes by in settings and on a delivery: log, smtp, ses, resend. */
    public function key(): string;

    /** Whether it has everything it needs to really send. */
    public function isConfigured(): bool;

    /** @return list<string> the settings it needs, for the screen that says what is missing */
    public function requires(): array;

    /** Whether a message it accepts leaves the building (false for the one that only writes it down). */
    public function delivers(): bool;

    /** Sends one email, or says why not. Never throws: a failure is a result. */
    public function send(OutboundEmail $email): DeliveryResult;
}
