<?php

namespace App\Domain\Conversions\Adapters;

use App\Domain\Conversions\Hashing;
use App\Models\ConversionExport;
use App\Support\GoogleAdsToken;

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
        return ['customer_id', 'developer_token', 'client_id', 'client_secret', 'refresh_token', 'conversion_action_id'];
    }

    protected function endpoint(array $credentials): string
    {
        $customer = ($credentials['customer_id'] ?? '');

        return "https://googleads.googleapis.com/v20/customers/{$customer}:uploadClickConversions";
    }

    /** OAuth access token minted from the refresh token, plus the developer token. */
    protected function headers(array $credentials, string $url, array $body): array
    {
        return array_filter([
            'Authorization' => 'Bearer '.GoogleAdsToken::mint((string) $credentials['client_id'], (string) $credentials['client_secret'], (string) $credentials['refresh_token']),
            'developer-token' => (string) $credentials['developer_token'],
            'login-customer-id' => isset($credentials['login_customer_id']) ? preg_replace('/\D/', '', (string) $credentials['login_customer_id']) : null,
        ]);
    }

    /** With partialFailure, Google answers 200 and reports a refused row in the body. */
    protected function accepted(array $json): bool
    {
        return empty($json['partialFailureError']);
    }

    protected function body(ConversionExport $export, array $credentials): array
    {
        $lead = $export->lead;

        return [
            'conversions' => [array_filter([
                'conversionAction' => 'customers/'.($credentials['customer_id'] ?? '')
                    .'/conversionActions/'.($credentials['conversion_action_id'] ?? ''),
                'conversionDateTime' => $export->occurred_at->format('Y-m-d H:i:sP'),
                // Google's deduplication key: the same order id is one conversion.
                'orderId' => 'leadflow-'.$export->id,
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
