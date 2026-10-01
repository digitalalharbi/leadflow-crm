<?php

namespace App\DataTransferObjects;

use App\Enums\IntegrationProvider;
use Illuminate\Support\Arr;

/**
 * Provider-agnostic shape every lead source is translated into before it reaches
 * the ingestion service. Providers are responsible for producing this; the
 * ingestion service never sees a raw provider payload shape.
 */
class IncomingLead
{
    public function __construct(
        public readonly IntegrationProvider $provider,
        public readonly ?string $fullName = null,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $phone = null,
        public readonly ?string $email = null,
        public readonly ?string $city = null,
        public readonly ?string $platform = null,
        public readonly ?string $source = null,
        public readonly ?string $campaignName = null,
        public readonly ?string $campaignId = null,
        public readonly ?string $adsetName = null,
        public readonly ?string $adsetId = null,
        public readonly ?string $adName = null,
        public readonly ?string $adId = null,
        public readonly ?string $formName = null,
        public readonly ?string $formId = null,
        public readonly ?string $providerLeadId = null,
        public readonly ?int $projectId = null,
        public readonly ?int $assignedTo = null,
        public readonly ?string $purchasePurpose = null,
        public readonly ?string $paymentMethod = null,
        public readonly ?string $preferredPropertyType = null,
        public readonly ?string $preferredLocation = null,
        public readonly ?string $purchaseTimeline = null,
        public readonly ?float $budgetMin = null,
        public readonly ?float $budgetMax = null,
        public readonly array $custom = [],
        public readonly array $rawPayload = [],
    ) {}

    /**
     * Build from an already-mapped associative array (webhook + CSV + sheets all
     * run their field mapping first, then land here).
     */
    public static function fromArray(IntegrationProvider $provider, array $data, array $raw = []): self
    {
        $first = Arr::get($data, 'first_name');
        $last = Arr::get($data, 'last_name');
        $full = Arr::get($data, 'full_name') ?: trim(($first ?? '').' '.($last ?? ''));

        if ($full === '' && $first === null && $last === null) {
            $full = null;
        }

        // Split a single full name so the UI always has something for both columns.
        if ($first === null && $full) {
            $parts = preg_split('/\s+/', trim($full), 2);
            $first = $parts[0] ?? null;
            $last = $parts[1] ?? null;
        }

        return new self(
            provider: $provider,
            fullName: $full ?: null,
            firstName: $first,
            lastName: $last,
            phone: Arr::get($data, 'phone'),
            email: Arr::get($data, 'email'),
            city: Arr::get($data, 'city'),
            platform: Arr::get($data, 'platform') ?: $provider->defaultPlatform()->value,
            source: Arr::get($data, 'source'),
            campaignName: Arr::get($data, 'campaign_name'),
            campaignId: Arr::get($data, 'campaign_id'),
            adsetName: Arr::get($data, 'adset_name'),
            adsetId: Arr::get($data, 'adset_id'),
            adName: Arr::get($data, 'ad_name'),
            adId: Arr::get($data, 'ad_id'),
            formName: Arr::get($data, 'form_name'),
            formId: Arr::get($data, 'form_id'),
            providerLeadId: Arr::get($data, 'provider_lead_id'),
            projectId: Arr::get($data, 'project_id'),
            assignedTo: Arr::get($data, 'assigned_to'),
            purchasePurpose: Arr::get($data, 'purchase_purpose'),
            paymentMethod: Arr::get($data, 'payment_method'),
            preferredPropertyType: Arr::get($data, 'preferred_property_type'),
            preferredLocation: Arr::get($data, 'preferred_location'),
            purchaseTimeline: Arr::get($data, 'purchase_timeline'),
            budgetMin: is_numeric(Arr::get($data, 'budget_min')) ? (float) Arr::get($data, 'budget_min') : null,
            budgetMax: is_numeric(Arr::get($data, 'budget_max')) ? (float) Arr::get($data, 'budget_max') : null,
            custom: Arr::get($data, 'custom', []),
            rawPayload: $raw ?: $data,
        );
    }

    public function hasIdentity(): bool
    {
        return filled($this->phone) || filled($this->email);
    }
}
