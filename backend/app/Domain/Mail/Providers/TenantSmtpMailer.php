<?php

namespace App\Domain\Mail\Providers;

use App\Domain\Mail\DeliveryResult;
use App\Domain\Mail\MailerProvider;
use App\Domain\Mail\OutboundEmail;
use App\Models\Integration;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * The mail server a workspace gave us — its own host, its own login, its own domain and reputation.
 *
 * Built from the workspace's email integration, so it is never chosen in settings and never in the
 * registry: it is the door a lead's email leaves by when the workspace has one, and nothing else
 * goes through it. The password is read here and nowhere else.
 */
final class TenantSmtpMailer implements MailerProvider
{
    use ShapesMessages;

    public function __construct(private Integration $integration) {}

    public function key(): string
    {
        return 'workspace_smtp';
    }

    public function isConfigured(): bool
    {
        return $this->requires() === [];
    }

    public function requires(): array
    {
        $config = $this->integration->config ?? [];

        return array_values(array_filter([
            blank($config['host'] ?? null) ? 'host' : null,
            blank($config['from_address'] ?? null) ? 'from_address' : null,
            blank($config['username'] ?? null) ? 'username' : null,
            blank($this->integration->credentials['password'] ?? null) ? 'password' : null,
        ]));
    }

    public function delivers(): bool
    {
        return true;
    }

    public function send(OutboundEmail $email): DeliveryResult
    {
        if (! $this->isConfigured()) {
            return DeliveryResult::failed('The workspace mail server is not configured: '.implode(', ', $this->requires()).' missing.', retryable: false);
        }

        $config = $this->integration->config;
        $encryption = $config['encryption'] ?? 'tls';

        try {
            $id = '<'.Str::uuid().'@'.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'mohtam.local').'>';

            Mail::build([
                'transport' => 'smtp',
                'host' => $config['host'],
                'port' => (int) ($config['port'] ?? 587),
                'username' => $config['username'],
                'password' => $this->integration->credentials['password'],
                // Symfony's DSN: "ssl" is implicit TLS on connect; STARTTLS is negotiated by the
                // transport itself when the server offers it.
                'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
                'timeout' => 15,
            ])->html($email->html, fn ($message) => $this->shape($message, $email, $id));

            return DeliveryResult::sent($email->headers['Message-ID'] ?? $id);
        } catch (Throwable $e) {
            return DeliveryResult::failed($e->getMessage(), retryable: ! $this->isPermanent($e));
        }
    }
}
