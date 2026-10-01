<?php

namespace App\Services\Whatsapp;

use App\DataTransferObjects\SentMessage;
use App\Models\Integration;
use Illuminate\Support\Str;

/**
 * Stands in for the Cloud API until a tenant has real Meta credentials.
 *
 * It is not a stub that returns true: it accepts the message, mints a provider
 * id in Meta's shape, and the rest of the system treats it exactly like a real
 * send — same table, same statuses, same webhook path for delivery receipts.
 * That way the day the credentials arrive, nothing else has to change.
 *
 * Never selected in production: see WhatsappManager.
 */
class MockWhatsappProvider implements WhatsappProviderInterface
{
    public function key(): string
    {
        return 'mock';
    }

    public function sendText(Integration $integration, string $toPhone, string $body): SentMessage
    {
        // A number the provider itself would refuse, so the failure path can be
        // demonstrated and tested without waiting on a real rejection.
        if (str_contains($toPhone, '000000')) {
            return SentMessage::failed('131026', 'Message undeliverable: the recipient is not on WhatsApp.');
        }

        return SentMessage::accepted(
            'wamid.mock.'.Str::lower(Str::random(24)),
            ['mock' => true, 'to' => $toPhone, 'length' => mb_strlen($body)],
        );
    }

    /**
     * The mock verifies through the same app_secret path as the real provider,
     * so a signature test exercises the real comparison.
     *
     * With no secret configured it accepts the delivery — that is what makes
     * the webhook demonstrable before any Meta app exists — but only in local
     * development and under test.
     *
     * Named environments are an allow-list, never a deny-list: `!environment(
     * 'production')` reads as "safe everywhere but production" and is in fact
     * "safe nowhere except where someone spelled production exactly", so an
     * APP_ENV of `prod` or `staging` would accept unsigned, unauthenticated
     * writes from anyone who knew a phone number id — which is a public Meta
     * identifier, not a secret.
     */
    public function verifySignature(Integration $integration, string $rawBody, ?string $signature): bool
    {
        $secret = $integration->credentials['app_secret'] ?? null;

        if (! $secret) {
            return app()->environment('local', 'testing');
        }

        return $signature !== null
            && hash_equals('sha256='.hash_hmac('sha256', $rawBody, $secret), $signature);
    }
}
