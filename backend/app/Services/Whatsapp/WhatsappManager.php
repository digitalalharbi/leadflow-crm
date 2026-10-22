<?php

namespace App\Services\Whatsapp;

use App\Enums\IntegrationProvider;
use App\Models\Integration;
use App\Support\TenantContext;

/**
 * Finds a tenant's WhatsApp integration and the provider that should serve it.
 *
 * The choice is made from what the integration actually holds, not from a
 * global switch: a workspace with real credentials talks to Meta, one without
 * talks to the mock, and both can exist in the same deployment. Production
 * never falls back to the mock — a message a customer thinks was sent, but
 * never left the building, is worse than a visible failure.
 */
class WhatsappManager
{
    public function __construct(
        private TenantContext $context,
        private WhatsappCloudProvider $cloud,
        private MockWhatsappProvider $mock,
    ) {}

    /** The current tenant's WhatsApp integration, if one has been created. */
    public function integration(): ?Integration
    {
        return Integration::query()
            ->where('provider', IntegrationProvider::Whatsapp)
            ->first();
    }

    /**
     * Route an inbound delivery to its tenant by the phone number it arrived on.
     *
     * Ordered, and active-only. A unique index now makes two workspaces holding
     * the same number impossible, but an unordered `first()` over a duplicate
     * would have let Postgres decide whose customer messages landed where — and
     * "it cannot happen" is not a reason to write a query that would be wrong
     * if it did.
     */
    public function integrationForPhoneNumberId(string $phoneNumberId): ?Integration
    {
        return $this->context->withoutScope(
            fn () => Integration::query()
                ->where('provider', IntegrationProvider::Whatsapp)
                ->where('is_active', true)
                ->whereRaw("config->>'phone_number_id' = ?", [$phoneNumberId])
                ->orderBy('id')
                ->first()
        );
    }

    public function providerFor(Integration $integration): WhatsappProviderInterface
    {
        return $this->isLive($integration) ? $this->cloud : $this->mock;
    }

    /**
     * Live means: every credential the Cloud API needs is present. A workspace
     * that has filled in half the form is still "configuration required".
     */
    public function isLive(?Integration $integration): bool
    {
        if ($integration === null) {
            return false;
        }

        $credentials = $integration->credentials ?? [];

        return filled($integration->config['phone_number_id'] ?? null)
            && filled($credentials['access_token'] ?? null);
    }

    /**
     * Whether messages may be sent at all.
     *
     * The mock exists for development and for showing the product, not for
     * pretending a customer was contacted — so anywhere that is not local or
     * test, an unconfigured workspace cannot send. An allow-list, for the same
     * reason as the signature check: a deny-list on the literal string
     * "production" quietly turns "staging" into a place that lies to reps.
     */
    public function canSend(?Integration $integration): bool
    {
        if ($integration === null || $integration->is_active === false) {
            return false;
        }

        return $this->isLive($integration) || app()->environment('local', 'testing');
    }

    public function status(?Integration $integration): string
    {
        return match (true) {
            $integration === null => Integration::NOT_CONNECTED,
            $integration->status === Integration::ERROR => Integration::ERROR,
            $this->isLive($integration) => Integration::CONNECTED,
            default => Integration::CONFIGURATION_REQUIRED,
        };
    }
}
