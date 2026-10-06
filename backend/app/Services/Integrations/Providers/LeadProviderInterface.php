<?php

namespace App\Services\Integrations\Providers;

use App\DataTransferObjects\IncomingLead;
use App\Models\Integration;

/**
 * Every lead source implements this. Adding Snapchat or a new ad network means
 * writing one class — nothing downstream of the ingestion service changes.
 */
interface LeadProviderInterface
{
    /** Stable provider key, matching App\Enums\IntegrationProvider. */
    public function key(): string;

    /** Translate one raw provider payload into the canonical shape. */
    public function toIncomingLead(array $payload, ?Integration $integration = null): IncomingLead;

    /** True when real API credentials are configured; false means sandbox/mock mode. */
    public function isConfigured(?Integration $integration = null): bool;

    /**
     * Pull leads from the provider's API. Push-based providers (webhooks) return [].
     *
     * @return array<int, array<string, mixed>> raw payloads
     */
    public function fetch(Integration $integration): array;

    /**
     * Do we have to go and ask this source, or does it call us?
     *
     * Explicit rather than inferred from `fetch()` returning rows, because the
     * honest answer for a misconfigured puller and for a push-only provider is
     * the same empty array — and the two need opposite handling. One is a
     * source to chase; the other is a source that is working.
     */
    public function pullsLeads(): bool;

    /** Credential keys this provider needs, used to render the settings form. */
    public function requiredCredentials(): array;
}
