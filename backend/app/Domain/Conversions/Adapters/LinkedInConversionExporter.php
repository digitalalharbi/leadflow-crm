<?php

namespace App\Domain\Conversions\Adapters;

use App\Domain\Conversions\ConversionEvent;
use App\Domain\Conversions\Hashing;
use App\Models\ConversionExport;
use RuntimeException;

/**
 * LinkedIn Conversions API. Each of our events maps to a conversion rule the
 * workspace created in Campaign Manager (conversion_qualified / _appointment /
 * _won, as numeric ids); an event with no rule is refused here, by name, rather
 * than sent to the wrong one.
 */
class LinkedInConversionExporter extends AbstractConversionExporter
{
    public function platform(): string
    {
        return 'linkedin';
    }

    public function requiredCredentials(): array
    {
        return ['access_token'];
    }

    protected function endpoint(array $credentials): string
    {
        return 'https://api.linkedin.com/rest/conversionEvents';
    }

    protected function headers(array $credentials, string $url, array $body): array
    {
        return [
            'Authorization' => 'Bearer '.($credentials['access_token'] ?? ''),
            'LinkedIn-Version' => (string) ($credentials['api_version'] ?? '202505'),
            'X-Restli-Protocol-Version' => '2.0.0',
        ];
    }

    protected function body(ConversionExport $export, array $credentials): array
    {
        $rule = $credentials['conversion_'.$export->event->value] ?? null;

        if (blank($rule)) {
            throw new RuntimeException("No LinkedIn conversion rule for {$export->event->value}.");
        }

        $lead = $export->lead;
        $email = Hashing::email($lead->email);

        return array_filter([
            'conversion' => 'urn:lla:llaPartnerConversion:'.preg_replace('/\D/', '', (string) $rule),
            'conversionHappenedAt' => $export->occurred_at->getTimestampMs(),
            'conversionValue' => $export->event === ConversionEvent::Won && $export->value
                ? ['currencyCode' => $export->currency, 'amount' => number_format((float) $export->value, 2, '.', '')]
                : null,
            'eventId' => 'leadflow-'.$export->id,
            'user' => ['userIds' => array_values(array_filter([
                $email ? ['idType' => 'SHA256_EMAIL', 'idValue' => $email] : null,
                $lead->click_id ? ['idType' => 'LINKEDIN_FIRST_PARTY_ADS_TRACKING_UUID', 'idValue' => $lead->click_id] : null,
            ]))],
        ]);
    }
}
