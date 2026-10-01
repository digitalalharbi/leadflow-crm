<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IntegrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'provider' => $this->provider?->value,
            'name' => $this->name,
            'status' => $this->status,
            'is_active' => $this->is_active,
            'config' => $this->config,
            'field_mapping' => $this->field_mapping,
            'defaults' => $this->defaults,
            // Which secrets are set — never the secrets themselves.
            'configured_credentials' => $this->configuredCredentialKeys(),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'leads_received' => (int) $this->leads_received,
            'webhook_endpoints' => $this->whenLoaded('webhookEndpoints', fn () => $this->webhookEndpoints->map(fn ($e) => [
                'id' => $e->uuid,
                'key' => $e->key,
                'label' => $e->label,
                'url' => url("/api/v1/integrations/webhooks/{$e->key}/leads"),
                'is_active' => $e->is_active,
                'last_received_at' => $e->last_received_at?->toIso8601String(),
                'received_count' => (int) $e->received_count,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
