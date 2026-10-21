<?php

namespace App\Services\Email;

use App\Domain\Mail\MailerProvider;
use App\Domain\Mail\MailerRegistry;
use App\Domain\Mail\Providers\TenantSmtpMailer;
use App\Enums\IntegrationProvider;
use App\Models\Integration;

/**
 * Which door an email to a lead leaves by.
 *
 * Three answers, and the difference between them is the whole honesty of the
 * channel:
 *
 *   smtp      the workspace gave us its own mail server, so the message leaves
 *             from its own domain and its own reputation
 *   platform  the product's own provider (App\Domain\Mail), configured and able
 *             to send. The customer's mail provider sees us rather than them
 *   mock      the product's provider only writes it down (the log), or has no
 *             keys: the pipeline runs, nothing is sent. Reported as such
 *             everywhere, because an email that was written to a log file and
 *             called "sent" is a lead who never heard back.
 *
 * Both doors are a MailerProvider; nothing here, or anywhere, reaches for
 * Laravel's default mailer.
 */
class EmailManager
{
    /** Stored encrypted; the password is never read back. */
    public const SECRETS = ['password'];

    public const CONFIG = ['host', 'port', 'username', 'encryption', 'from_address', 'from_name'];

    public function __construct(private MailerRegistry $registry) {}

    public function integration(): ?Integration
    {
        return Integration::where('provider', IntegrationProvider::Email->value)->first();
    }

    /** Whether the workspace has given us a complete mail server. */
    public function isLive(?Integration $integration): bool
    {
        if (! $integration) {
            return false;
        }

        return filled($integration->config['host'] ?? null)
            && filled($integration->config['from_address'] ?? null)
            && filled($integration->config['username'] ?? null)
            && filled($integration->credentials['password'] ?? null);
    }

    /** @return 'smtp'|'platform'|'mock' */
    public function provider(?Integration $integration = null): string
    {
        $integration ??= $this->integration();

        if ($this->isLive($integration)) {
            return 'smtp';
        }

        $door = $this->registry->current();

        return $door->delivers() && $door->isConfigured() ? 'platform' : 'mock';
    }

    public function canSend(?Integration $integration = null): bool
    {
        // The mock "sends" too, which is why `mode` exists: the button works,
        // and the screen says what working means.
        return true;
    }

    /** The door itself: the workspace's own server when it gave one, else the product's provider. */
    public function door(?Integration $integration = null): MailerProvider
    {
        $integration ??= $this->integration();

        return $this->isLive($integration) ? new TenantSmtpMailer($integration) : $this->registry->current();
    }

    /**
     * The visible sender.
     *
     * The workspace's own address when it gave one. Otherwise the platform's,
     * under the company's name — and the lead's reply goes to the rep's
     * workspace, not to a no-reply box that nobody reads.
     *
     * @return array{address:string,name:string}
     */
    public function from(?Integration $integration, ?string $company): array
    {
        if ($this->isLive($integration)) {
            return [
                'address' => $integration->config['from_address'],
                'name' => ($integration->config['from_name'] ?? null) ?: (string) $company,
            ];
        }

        return [
            'address' => (string) config('leadflow_mail.from.address'),
            'name' => $company ?: (string) config('leadflow_mail.from.name'),
        ];
    }
}
