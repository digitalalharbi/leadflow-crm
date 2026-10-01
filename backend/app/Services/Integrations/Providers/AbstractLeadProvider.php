<?php

namespace App\Services\Integrations\Providers;

use App\DataTransferObjects\IncomingLead;
use App\Enums\IntegrationProvider;
use App\Models\Integration;
use Illuminate\Support\Arr;

abstract class AbstractLeadProvider implements LeadProviderInterface
{
    abstract public function provider(): IntegrationProvider;

    public function key(): string
    {
        return $this->provider()->value;
    }

    public function requiredCredentials(): array
    {
        return [];
    }

    public function isConfigured(?Integration $integration = null): bool
    {
        $required = $this->requiredCredentials();

        if ($required === []) {
            return true;
        }

        $credentials = $integration?->credentials ?? [];

        foreach ($required as $key) {
            if (blank(Arr::get($credentials, $key) ?? config("integrations.{$this->key()}.{$key}"))) {
                return false;
            }
        }

        return true;
    }

    public function fetch(Integration $integration): array
    {
        return [];
    }

    /**
     * Apply the tenant's field mapping, then the provider's own aliases.
     * Mapping is stored as { "incoming_key": "canonical_key" }.
     */
    protected function applyMapping(array $payload, ?Integration $integration): array
    {
        $flat = $this->flatten($payload);
        $mapped = [];

        $mapping = $integration?->field_mapping ?? [];

        foreach ($flat as $key => $value) {
            $canonical = $mapping[$key]
                ?? $mapping[strtolower($key)]
                ?? $this->aliases()[strtolower($key)]
                ?? (in_array($key, $this->canonicalKeys(), true) ? $key : null);

            if ($canonical !== null && ! isset($mapped[$canonical])) {
                $mapped[$canonical] = $value;
            }
        }

        foreach ($integration?->defaults ?? [] as $key => $value) {
            if (! in_array($key, $this->defaultableKeys(), true)) {
                continue;
            }

            if (blank($mapped[$key] ?? null) && filled($value)) {
                $mapped[$key] = $value;
            }
        }

        return $mapped;
    }

    /** Dot-flatten so nested provider payloads can be mapped with simple keys. */
    protected function flatten(array $payload): array
    {
        $flat = Arr::dot($payload);
        $result = [];

        foreach ($flat as $key => $value) {
            if (is_array($value)) {
                continue;
            }
            $result[$key] = $value;
            // also expose the leaf name on its own ("lead.phone" => "phone")
            $leaf = substr(strrchr($key, '.') ?: $key, 1) ?: $key;
            if (! isset($result[$leaf])) {
                $result[$leaf] = $value;
            }
        }

        return $result;
    }

    /** Common inbound names seen across landing pages, sheets and ad platforms. */
    protected function aliases(): array
    {
        return [
            'name' => 'full_name',
            'fullname' => 'full_name',
            'full name' => 'full_name',
            'الاسم' => 'full_name',
            'client_name' => 'full_name',
            'customer_name' => 'full_name',
            'firstname' => 'first_name',
            'lastname' => 'last_name',
            'mobile' => 'phone',
            'mobile_number' => 'phone',
            'phone_number' => 'phone',
            'tel' => 'phone',
            'whatsapp' => 'phone',
            'الجوال' => 'phone',
            'رقم_الجوال' => 'phone',
            'email_address' => 'email',
            'mail' => 'email',
            'البريد' => 'email',
            'city_name' => 'city',
            'المدينة' => 'city',
            'campaign' => 'campaign_name',
            'campaign_title' => 'campaign_name',
            'adset' => 'adset_name',
            'ad_set_name' => 'adset_name',
            'ad' => 'ad_name',
            'creative_name' => 'ad_name',
            'form' => 'form_name',
            'budget' => 'budget_max',
            'utm_source' => 'source',
            'utm_campaign' => 'campaign_name',
            'utm_medium' => 'source',
        ];
    }

    /**
     * Fields an untrusted payload is allowed to set.
     *
     * Internal foreign keys (project_id, assigned_to) are deliberately absent:
     * they address global, sequential ids, so accepting them from a webhook body
     * would let one tenant point a lead at another tenant's row. Those are set
     * from tenant configuration instead, and re-resolved under the tenant scope
     * by LeadIngestionService.
     */
    protected function canonicalKeys(): array
    {
        return [
            'full_name', 'first_name', 'last_name', 'phone', 'email', 'city',
            'platform', 'source', 'campaign_name', 'campaign_id', 'adset_name',
            'adset_id', 'ad_name', 'ad_id', 'form_name', 'form_id',
            'provider_lead_id',
            'purchase_purpose', 'payment_method', 'preferred_property_type',
            'preferred_location', 'purchase_timeline', 'budget_min', 'budget_max',
        ];
    }

    /** Fields an incoming payload may be mapped onto. */
    public function mappableKeys(): array
    {
        return $this->canonicalKeys();
    }

    /**
     * Keys a tenant may set through integration defaults. `project_id` and
     * `assigned_to` are allowed here — unlike in a payload mapping — because an
     * authenticated admin sets them, they are supplied as UUIDs, and they are
     * resolved under the tenant scope before being stored.
     */
    public function defaultableKeys(): array
    {
        return array_merge($this->canonicalKeys(), ['project_id', 'assigned_to']);
    }

    public function toIncomingLead(array $payload, ?Integration $integration = null): IncomingLead
    {
        $mapped = $this->applyMapping($payload, $integration);

        if (! isset($mapped['platform'])) {
            $mapped['platform'] = $this->provider()->defaultPlatform()->value;
        }

        return IncomingLead::fromArray($this->provider(), $mapped, $payload);
    }
}
