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

    /**
     * Meta-style lead forms answer as a list of {name, values[]} rather than a
     * flat object. Understood by every provider, not just the Meta one, because
     * these payloads commonly arrive at a generic endpoint via Zapier or Make.
     */
    protected function normaliseAnswerList(array $payload): array
    {
        $answers = $payload['field_data'] ?? $payload['form_response'] ?? null;

        if (! is_array($answers)) {
            return $payload;
        }

        $flat = [];

        foreach ($answers as $field) {
            if (! is_array($field)) {
                continue;
            }

            $name = Arr::get($field, 'name') ?? Arr::get($field, 'key');
            $value = Arr::get($field, 'values.0') ?? Arr::get($field, 'value');

            if (is_string($name) && $value !== null && ! is_array($value)) {
                $flat[$name] = $value;
            }
        }

        if ($flat === []) {
            return $payload;
        }

        unset($payload['field_data'], $payload['form_response']);

        // In this envelope `id` is the provider's own lead id, which is what makes
        // a retried delivery traceable. Outside it, `id` is too generic to trust.
        foreach (['id', 'lead_id'] as $candidate) {
            if (filled($payload[$candidate] ?? null) && blank($payload['provider_lead_id'] ?? null)) {
                $payload['provider_lead_id'] = $payload[$candidate];
                break;
            }
        }

        // The envelope's own fields (campaign, ad, form) still apply; the answers
        // win where both carry the same key.
        return $flat + $payload;
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

            // Also expose the leaf of a nested key on its own ("lead.phone" =>
            // "phone"). A key with no dot has no leaf: taking one anyway would
            // let "xcity" pass itself off as "city".
            if (! str_contains($key, '.')) {
                continue;
            }

            $leaf = substr($key, strrpos($key, '.') + 1);

            if ($leaf !== '' && ! isset($result[$leaf])) {
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
        $payload = $this->normaliseAnswerList($payload);

        $mapped = $this->applyMapping($payload, $integration);

        if (! isset($mapped['platform'])) {
            $mapped['platform'] = $this->provider()->defaultPlatform()->value;
        }

        return IncomingLead::fromArray($this->provider(), $mapped, $payload);
    }
}
