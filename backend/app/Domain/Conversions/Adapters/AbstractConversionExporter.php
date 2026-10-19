<?php

namespace App\Domain\Conversions\Adapters;

use App\Domain\Conversions\ConversionExporterInterface;
use App\Models\ConversionExport;
use Illuminate\Support\Facades\Http;

/**
 * What every conversion upload shares: credential checks, one request with the
 * platform's own authentication, and a summary that keeps no identifier.
 *
 * Idempotency is the platform's: every exporter sends a stable id derived from
 * the export row ("leadflow-{id}"), so a retry the queue makes after a timeout
 * is recognised as the same conversion and counted once.
 */
abstract class AbstractConversionExporter implements ConversionExporterInterface
{
    /** Short, because a conversion upload must never hold a queue worker open. */
    protected int $timeoutSeconds = 10;

    abstract protected function endpoint(array $credentials): string;

    /** @return array<string,mixed> */
    abstract protected function body(ConversionExport $export, array $credentials): array;

    /** @return array<string,string> authentication headers for this platform */
    protected function headers(array $credentials, string $url, array $body): array
    {
        return [];
    }

    public function isConfigured(?array $credentials): bool
    {
        if ($credentials === null) {
            return false;
        }

        foreach ($this->requiredCredentials() as $key) {
            if (blank($credentials[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    public function send(ConversionExport $export, array $credentials): array
    {
        try {
            $body = $this->body($export, $credentials);
            $url = $this->endpoint($credentials);
            $response = Http::timeout($this->timeoutSeconds)
                ->withHeaders($this->headers($credentials, $url, $body))
                ->asJson()
                ->post($url, $body);
        } catch (\Throwable $e) {
            // The message, never the payload: it holds hashed identifiers.
            return ['ok' => false, 'summary' => isset($body) ? $this->summarise($body) : [], 'error' => $e->getMessage()];
        }

        if ($response->successful() && $this->accepted($response->json() ?? [])) {
            return ['ok' => true, 'summary' => $this->summarise($body)];
        }

        return [
            'ok' => false,
            'summary' => $this->summarise($body),
            'error' => 'HTTP '.$response->status().': '.mb_substr($response->body(), 0, 500),
        ];
    }

    /** Some platforms answer 200 and refuse in the body. */
    protected function accepted(array $json): bool
    {
        return true;
    }

    protected function summarise(array $body): array
    {
        return [
            'event' => data_get($body, 'event_name') ?? data_get($body, 'data.0.event_name') ?? data_get($body, 'data.0.event') ?? data_get($body, 'event'),
            'fields_sent' => array_keys($body),
            'identifiers' => $this->identifierKinds($body),
        ];
    }

    /** Which kinds of identifier were included, not their values. */
    private function identifierKinds(array $body): array
    {
        $flat = json_encode($body) ?: '';

        return array_values(array_filter([
            preg_match('/"(ph|phone|hashedPhoneNumber|hashed_phone_number)"/', $flat) ? 'phone' : null,
            preg_match('/"(em|email|hashedEmail|hashed_email|SHA256_EMAIL)"/', $flat) ? 'email' : null,
            preg_match('/clid|click_id|gclid|twclid|lead_id/', $flat) ? 'click_id' : null,
        ]));
    }
}
