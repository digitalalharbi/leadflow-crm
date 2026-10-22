<?php

namespace App\Domain\Conversions\Adapters;

use App\Domain\Conversions\Hashing;
use App\Models\ConversionExport;
use App\Support\OAuth1;
use RuntimeException;

/**
 * X Conversion API, signed with OAuth 1.0a. Each of our events maps to an
 * event id from the workspace's X pixel (event_qualified / _appointment / _won).
 */
class XConversionExporter extends AbstractConversionExporter
{
    public function platform(): string
    {
        return 'x';
    }

    public function requiredCredentials(): array
    {
        return ['consumer_key', 'consumer_secret', 'access_token', 'access_token_secret', 'pixel_id'];
    }

    protected function endpoint(array $credentials): string
    {
        return 'https://ads-api.x.com/12/measurement/conversions/'.rawurlencode((string) ($credentials['pixel_id'] ?? ''));
    }

    /** A JSON body is not part of the OAuth 1.0a signature; only the URL and the oauth_* fields are. */
    protected function headers(array $credentials, string $url, array $body): array
    {
        return ['Authorization' => OAuth1::header(
            'POST', $url, [],
            (string) $credentials['consumer_key'], (string) $credentials['consumer_secret'],
            (string) $credentials['access_token'], (string) $credentials['access_token_secret'],
        )];
    }

    protected function body(ConversionExport $export, array $credentials): array
    {
        $eventId = $credentials['event_'.$export->event->value] ?? null;

        if (blank($eventId)) {
            throw new RuntimeException("No X pixel event for {$export->event->value}.");
        }

        $lead = $export->lead;

        return ['conversions' => [array_filter([
            'conversion_time' => $export->occurred_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'event_id' => (string) $eventId,
            'conversion_id' => 'leadflow-'.$export->id,
            'value' => $export->value !== null ? (string) $export->value : null,
            'identifiers' => array_values(array_filter([
                ($e = Hashing::email($lead->email)) ? ['hashed_email' => $e] : null,
                ($p = Hashing::phone($lead->phone_normalized ?? $lead->phone)) ? ['hashed_phone_number' => $p] : null,
                $lead->click_id ? ['twclid' => $lead->click_id] : null,
            ])),
        ])]];
    }
}
