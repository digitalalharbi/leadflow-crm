<?php

namespace Tests\Support;

use App\Domain\Mail\DeliveryResult;
use App\Domain\Mail\MailerProvider;
use App\Domain\Mail\OutboundEmail;

/**
 * A provider a test can read and script: every email that reached it, and what it answers.
 *
 * By default it writes the email down and reports it so (`delivers()` false, like the log), which
 * is what the product's own honesty expects of a door with nothing behind it. A test that needs a
 * door that "really sends" flips `delivers`; one that needs failures scripts them.
 */
final class FakeMailer implements MailerProvider
{
    /** @var list<OutboundEmail> */
    public array $sent = [];

    /** @var list<DeliveryResult> */
    private array $script = [];

    private ?DeliveryResult $always = null;

    public function __construct(public string $name = 'fake', public bool $delivers = false) {}

    public function key(): string
    {
        return $this->name;
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
        return $this->delivers;
    }

    public function send(OutboundEmail $email): DeliveryResult
    {
        $this->sent[] = $email;

        if ($this->always !== null) {
            return $this->always;
        }

        return array_shift($this->script) ?? ($this->delivers
            ? DeliveryResult::sent('<fake-'.count($this->sent).'@fake.test>')
            : DeliveryResult::logged('fake-'.count($this->sent)));
    }

    /** Every send from now on fails like this. */
    public function failWith(string $error, bool $retryable): self
    {
        $this->always = DeliveryResult::failed($error, $retryable);

        return $this;
    }

    /** The next sends answer these, in order; after the last, the default answer. */
    public function script(DeliveryResult ...$results): self
    {
        $this->script = array_values($results);

        return $this;
    }

    /** The first email sent to this address, or null. */
    public function to(string $address): ?OutboundEmail
    {
        foreach ($this->sent as $email) {
            if (strcasecmp($email->toEmail, $address) === 0) {
                return $email;
            }
        }

        return null;
    }

    public function reset(): void
    {
        $this->sent = [];
    }
}
