<?php

namespace App\Domain\Conversions\Adapters;

use App\Domain\Conversions\ConversionExporterInterface;
use App\Models\ConversionExport;
use App\Models\Integration;
use Illuminate\Support\Facades\Http;

/**
 * What every platform's upload has in common.
 *
 * Each concrete adapter supplies an endpoint and the shape of one event; this
 * holds the parts that must not vary — how credentials are read, how long we
 * wait, and the rule that nothing identifying a person is written to our own
 * logs or stored in the export row.
 */
abstract class AbstractConversionExporter implements ConversionExporterInterface
{
    /** Short, because a conversion upload must never hold a queue worker open. */
    protected int $timeoutSeconds = 10;

    abstract protected function endpoint(Integration $integration): string;

    /** @return array<string,mixed> the request body for one conversion */
    abstract protected function body(ConversionExport $export, Integration $integration): array;

    public function isConfigured(?Integration $integration): bool
    {
        if (! $integration || ! $integration->is_active) {
            return false;
        }

        foreach ($this->requiredCredentials() as $key) {
            if (blank(data_get($integration->credentials, $key))) {
                return false;
            }
        }

        return true;
    }

    public function send(ConversionExport $export, Integration $integration): array
    {
        $body = $this->body($export, $integration);

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->asJson()
                ->post($this->endpoint($integration), $body);
        } catch (\Throwable $e) {
            // The message, never the payload: the payload contains the
            // customer's hashed identifiers and we do not need a second copy of
            // them in an exception log.
            return ['ok' => false, 'summary' => $this->summarise($body), 'error' => $e->getMessage()];
        }

        if ($response->successful()) {
            return ['ok' => true, 'summary' => $this->summarise($body)];
        }

        return [
            'ok' => false,
            'summary' => $this->summarise($body),
            'error' => 'HTTP '.$response->status().': '.mb_substr($response->body(), 0, 500),
        ];
    }

    /**
     * What we keep about a send: enough to debug, nothing that identifies a
     * person. Hashed identifiers are still personal data — a hash of a phone
     * number is a reliable way to recognise that phone number again.
     */
    protected function summarise(array $body): array
    {
        return [
            'event' => data_get($body, 'event_name') ?? data_get($body, 'event'),
            'fields_sent' => $this->fieldNames($body),
            'identifiers' => $this->identifierKinds($body),
        ];
    }

    private function fieldNames(array $body): array
    {
        return array_keys($body);
    }

    /** Which kinds of identifier were included, not their values. */
    private function identifierKinds(array $body): array
    {
        $flat = json_encode($body) ?: '';

        return array_values(array_filter([
            str_contains($flat, 'ph') ? 'phone' : null,
            str_contains($flat, 'em') ? 'email' : null,
            str_contains($flat, 'click') || str_contains($flat, 'clid') ? 'click_id' : null,
        ]));
    }
}
