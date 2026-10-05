<?php

namespace App\Domain\Conversions\Adapters;

use App\Domain\Conversions\Hashing;
use App\Models\ConversionExport;
use App\Models\Integration;

/**
 * Google Ads offline conversion import.
 *
 * Google matches on the GCLID where there is one and falls back to Enhanced
 * Conversions for Leads — hashed email and phone — where there is not. A lead
 * captured without a GCLID is still worth uploading; it simply matches less
 * often, and a workspace is better off knowing that than sending nothing.
 */
class GoogleAdsConversionExporter extends AbstractConversionExporter
{
    public function platform(): string
    {
        return 'google_ads';
    }

    public function requiredCredentials(): array
    {
        return ['customer_id', 'developer_token', 'refresh_token', 'conversion_action_id'];
    }

    protected function endpoint(Integration $integration): string
    {
        $customer = data_get($integration->credentials, 'customer_id');

        return "https://googleads.googleapis.com/v18/customers/{$customer}:uploadClickConversions";
    }

    protected function body(ConversionExport $export, Integration $integration): array
    {
        $lead = $export->lead;

        return [
            'conversions' => [array_filter([
                'conversionAction' => 'customers/'.data_get($integration->credentials, 'customer_id')
                    .'/conversionActions/'.data_get($integration->credentials, 'conversion_action_id'),
                'conversionDateTime' => $export->occurred_at->format('Y-m-d H:i:sP'),
                'conversionValue' => $export->value,
                'currencyCode' => $export->currency,
                'gclid' => $lead->click_id,
                'userIdentifiers' => array_values(array_filter([
                    $lead->email ? ['hashedEmail' => Hashing::email($lead->email)] : null,
                    ($lead->phone_normalized ?? $lead->phone)
                        ? ['hashedPhoneNumber' => Hashing::phone($lead->phone_normalized ?? $lead->phone)]
                        : null,
                ])),
            ])],
            'partialFailure' => true,
        ];
    }
}
