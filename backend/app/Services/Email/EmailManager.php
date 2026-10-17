<?php

namespace App\Services\Email;

use App\Enums\IntegrationProvider;
use App\Models\Integration;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

/**
 * Which door an email leaves by.
 *
 * Three answers, and the difference between them is the whole honesty of the
 * channel:
 *
 *   smtp      the workspace gave us its own mail server, so the message leaves
 *             from its own domain and its own reputation
 *   platform  our shared transport. It works, and the customer's mail provider
 *             sees us rather than them
 *   mock      the log or array transport: the pipeline runs, nothing is sent.
 *             Reported as such everywhere, because an email that was written
 *             to a log file and called "sent" is a lead who never heard back.
 */
class EmailManager
{
    /** Stored encrypted; the password is never read back. */
    public const SECRETS = ['password'];

    public const CONFIG = ['host', 'port', 'username', 'encryption', 'from_address', 'from_name'];

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

        return in_array(config('mail.default'), ['log', 'array', 'null'], true) ? 'mock' : 'platform';
    }

    public function canSend(?Integration $integration = null): bool
    {
        // The mock "sends" too, which is why `mode` exists: the button works,
        // and the screen says what working means.
        return true;
    }

    public function mailer(?Integration $integration = null): Mailer
    {
        $integration ??= $this->integration();

        if (! $this->isLive($integration)) {
            return Mail::mailer();
        }

        $encryption = $integration->config['encryption'] ?? 'tls';

        return Mail::build([
            'transport' => 'smtp',
            'host' => $integration->config['host'],
            'port' => (int) ($integration->config['port'] ?? 587),
            'username' => $integration->config['username'],
            'password' => $integration->credentials['password'],
            // Symfony's DSN: "ssl" is implicit TLS on connect; STARTTLS is
            // negotiated by the transport itself when the server offers it.
            'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'timeout' => 15,
        ]);
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
            'address' => (string) config('mail.from.address'),
            'name' => $company ?: (string) config('mail.from.name'),
        ];
    }
}
