<?php

namespace App\Domain\Campaigns\Adapters;

use App\Domain\Campaigns\AdPlatform;
use App\Models\AdAccount;
use App\Models\AdConnection;
use App\Support\GoogleAdsToken;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Ads API (REST, GAQL).
 *
 * Every read is one GAQL query through searchStream; every write is a mutate
 * with an explicit update mask, so nothing outside the named field can change.
 * Access tokens are minted from the refresh token and cached for their life.
 * Money is in micros. Hierarchy: campaign → ad group → ad group ad.
 */
class GoogleAdsAdapter extends AbstractAdAdapter
{
    protected const SUPPORTED = ['pause', 'resume', 'update_budget', 'create_campaign', 'schedule'];

    protected const UNSUPPORTED_BECAUSE = [
        'duplicate_ad_set' => 'platform_has_no_copy',
        'create_ad' => 'needs_asset_editor',
        'update_creative' => 'needs_asset_editor',
        'update_targeting' => 'criteria_not_built',
    ];

    public function platform(): string
    {
        return AdPlatform::GoogleAds->value;
    }

    public function requiredCredentials(): array
    {
        return ['developer_token', 'client_id', 'client_secret', 'refresh_token'];
    }

    public function optionalSettings(): array
    {
        return ['account_ids', 'login_customer_id', 'api_version'];
    }

    public function accounts(AdConnection $connection): array
    {
        $ids = array_map('strval', (array) $connection->setting('account_ids', []));

        if ($ids === []) {
            $response = $this->client($connection)->get($this->base($connection).'/customers:listAccessibleCustomers');
            $this->check($response->failed(), $response);
            $ids = array_map(fn ($name) => substr($name, strlen('customers/')), $response->json('resourceNames') ?? []);
        }

        $accounts = [];
        foreach ($ids as $id) {
            try {
                $row = $this->query($connection, $id, 'SELECT customer.id, customer.descriptive_name, customer.currency_code, customer.manager, customer.status FROM customer')[0] ?? null;
            } catch (RuntimeException) {
                continue; // a manager account or one this login cannot read
            }

            if ($row === null || ($row['customer']['manager'] ?? false)) {
                continue;
            }

            $accounts[] = [
                'external_id' => (string) $row['customer']['id'],
                'name' => $row['customer']['descriptiveName'] ?? null,
                'currency' => $row['customer']['currencyCode'] ?? null,
                'status' => ($row['customer']['status'] ?? 'ENABLED') === 'ENABLED' ? 'active' : 'paused',
            ];
        }

        return $accounts;
    }

    public function structure(AdConnection $connection, AdAccount $account): array
    {
        $id = $account->external_id;

        $campaigns = array_map(fn (array $r) => [
            'external_id' => (string) $r['campaign']['id'],
            'name' => (string) $r['campaign']['name'],
            'status' => $this->status($r['campaign']['status'] ?? null),
            'objective' => $r['campaign']['advertisingChannelType'] ?? null,
            'daily_budget_minor' => self::minorFromMicros($r['campaignBudget']['amountMicros'] ?? null),
            'lifetime_budget_minor' => null,
            'starts_at' => $r['campaign']['startDate'] ?? null,
            'ends_at' => $r['campaign']['endDate'] ?? null,
        ], $this->query($connection, $id, "SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type, campaign.start_date, campaign.end_date, campaign_budget.amount_micros FROM campaign WHERE campaign.status != 'REMOVED'"));

        $adSets = array_map(fn (array $r) => [
            'external_id' => (string) $r['adGroup']['id'],
            'campaign_external_id' => (string) $r['campaign']['id'],
            'name' => (string) $r['adGroup']['name'],
            'status' => $this->status($r['adGroup']['status'] ?? null),
            'daily_budget_minor' => null,
        ], $this->query($connection, $id, "SELECT ad_group.id, ad_group.name, ad_group.status, campaign.id FROM ad_group WHERE ad_group.status != 'REMOVED'"));

        $ads = array_map(function (array $r) {
            $ad = $r['adGroupAd']['ad'] ?? [];
            $headline = $ad['responsiveSearchAd']['headlines'][0]['text'] ?? null;

            return [
                'external_id' => (string) $ad['id'],
                'campaign_external_id' => (string) $r['campaign']['id'],
                'ad_set_external_id' => (string) $r['adGroup']['id'],
                'name' => (string) ($ad['name'] ?? $headline ?? $ad['id']),
                'status' => $this->status($r['adGroupAd']['status'] ?? null),
                'creative' => [
                    'external_id' => 'ad:'.$ad['id'],
                    'name' => $ad['name'] ?? null,
                    'format' => 'text',
                    'headline' => $headline,
                    'body' => $ad['responsiveSearchAd']['descriptions'][0]['text'] ?? null,
                    'call_to_action' => null,
                    'media_url' => null,
                    'thumbnail_url' => null,
                ],
            ];
        }, $this->query($connection, $id, "SELECT ad_group_ad.ad.id, ad_group_ad.ad.name, ad_group_ad.status, ad_group_ad.ad.responsive_search_ad.headlines, ad_group_ad.ad.responsive_search_ad.descriptions, ad_group.id, campaign.id FROM ad_group_ad WHERE ad_group_ad.status != 'REMOVED'"));

        return ['campaigns' => $campaigns, 'ad_sets' => $adSets, 'ads' => $ads];
    }

    public function metrics(AdConnection $connection, AdAccount $account, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = $this->query($connection, $account->external_id, sprintf(
            "SELECT campaign.id, ad_group.id, ad_group_ad.ad.id, segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value FROM ad_group_ad WHERE segments.date BETWEEN '%s' AND '%s'",
            $from->toDateString(), $to->toDateString(),
        ));

        return array_map(fn (array $r) => [
            'level' => 'ad',
            'campaign_external_id' => (string) $r['campaign']['id'],
            'ad_set_external_id' => (string) $r['adGroup']['id'],
            'ad_external_id' => (string) $r['adGroupAd']['ad']['id'],
            'date' => (string) $r['segments']['date'],
            'spend_minor' => self::minorFromMicros($r['metrics']['costMicros'] ?? 0) ?? 0,
            'impressions' => (int) ($r['metrics']['impressions'] ?? 0),
            'clicks' => (int) ($r['metrics']['clicks'] ?? 0),
            // Google counts the account's conversion actions; for a lead
            // campaign that is the lead. It cannot tell a lead from a sale here.
            'leads' => (int) round((float) ($r['metrics']['conversions'] ?? 0)),
            'purchases' => 0,
            'revenue_minor' => (int) round(((float) ($r['metrics']['conversionsValue'] ?? 0)) * 100),
            'currency' => $account->currency,
        ], $rows);
    }

    public function leadForms(AdConnection $connection, AdAccount $account): ?array
    {
        return array_map(fn (array $r) => [
            'external_id' => (string) $r['asset']['id'],
            'name' => (string) ($r['asset']['name'] ?? $r['asset']['leadFormAsset']['headline'] ?? $r['asset']['id']),
            'status' => null,
        ], $this->query($connection, $account->external_id, "SELECT asset.id, asset.name, asset.lead_form_asset.headline FROM asset WHERE asset.type = 'LEAD_FORM'"));
    }

    // ------------------------------------------------------------- actions

    protected function pause(AdConnection $c, array $target, array $payload): array
    {
        return $this->setStatus($c, $target, 'PAUSED');
    }

    protected function resume(AdConnection $c, array $target, array $payload): array
    {
        return $this->setStatus($c, $target, 'ENABLED');
    }

    protected function updateBudget(AdConnection $c, array $target, array $payload): array
    {
        $campaign = preg_replace('/\D/', '', $this->requireTarget($target, 'campaign'));
        $customer = $this->requireAccount($target);
        $budget = $this->query($c, $customer, "SELECT campaign.campaign_budget FROM campaign WHERE campaign.id = {$campaign}")[0]['campaign']['campaignBudget'] ?? null;

        if (! $budget) {
            throw new RuntimeException('This campaign has no budget Google will let us change.');
        }

        return $this->mutate($c, $customer, 'campaignBudgets', [[
            'update' => ['resourceName' => $budget, 'amountMicros' => (string) (((int) ($payload['daily_budget_minor'] ?? 0)) * 10_000)],
            'updateMask' => 'amount_micros',
        ]]);
    }

    /** Two mutates: the budget, then the campaign on it — always created PAUSED. */
    protected function createCampaign(AdConnection $c, array $target, array $payload): array
    {
        $customer = $this->requireAccount($target);
        $micros = (string) (((int) ($payload['daily_budget_minor'] ?? 1000)) * 10_000);

        $budget = $this->mutate($c, $customer, 'campaignBudgets', [[
            'create' => ['name' => ($payload['name'] ?? 'Campaign').' budget '.now()->timestamp, 'amountMicros' => $micros, 'deliveryMethod' => 'STANDARD', 'explicitlyShared' => false],
        ]]);

        if (! $budget['ok']) {
            return $budget;
        }

        $budgetName = $budget['response']['body']['results'][0]['resourceName'] ?? null;

        $result = $this->mutate($c, $customer, 'campaigns', [[
            'create' => [
                'name' => (string) ($payload['name'] ?? 'Campaign'),
                'status' => 'PAUSED',
                'advertisingChannelType' => (string) ($payload['channel'] ?? 'SEARCH'),
                'campaignBudget' => $budgetName,
                'manualCpc' => (object) [],
                'networkSettings' => ['targetGoogleSearch' => true, 'targetSearchNetwork' => true],
                'containsEuPoliticalAdvertising' => 'DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING',
            ],
        ]]);

        $name = $result['response']['body']['results'][0]['resourceName'] ?? null;
        $result['created_external_id'] = $name ? substr($name, strrpos($name, '/') + 1) : null;

        return $result;
    }

    protected function schedule(AdConnection $c, array $target, array $payload): array
    {
        $campaign = $this->requireTarget($target, 'campaign');
        $customer = $this->requireAccount($target);
        $fields = array_filter([
            'startDate' => isset($payload['starts_at']) ? date('Y-m-d', strtotime($payload['starts_at'])) : null,
            'endDate' => isset($payload['ends_at']) ? date('Y-m-d', strtotime($payload['ends_at'])) : null,
        ]);

        return $this->mutate($c, $customer, 'campaigns', [[
            'update' => ['resourceName' => "customers/{$customer}/campaigns/{$campaign}"] + $fields,
            'updateMask' => implode(',', array_map(fn ($k) => $k === 'startDate' ? 'start_date' : 'end_date', array_keys($fields))),
        ]]);
    }

    // ------------------------------------------------------------- plumbing

    private function setStatus(AdConnection $c, array $target, string $status): array
    {
        $id = $this->requireTarget($target, 'campaign', 'ad_set', 'ad');
        $customer = $this->requireAccount($target);

        [$service, $resource] = match ($target['level']) {
            'campaign' => ['campaigns', "customers/{$customer}/campaigns/{$id}"],
            'ad_set' => ['adGroups', "customers/{$customer}/adGroups/{$id}"],
            'ad' => ['adGroupAds', "customers/{$customer}/adGroupAds/".($target['parent_external_id'] ?? throw new RuntimeException('The ad group of this ad is unknown.'))."~{$id}"],
        };

        return $this->mutate($c, $customer, $service, [['update' => ['resourceName' => $resource, 'status' => $status], 'updateMask' => 'status']]);
    }

    private function mutate(AdConnection $c, string $customer, string $service, array $operations): array
    {
        $path = "/customers/{$customer}/{$service}:mutate";
        $response = $this->client($c, $customer)->post($this->base($c).$path, ['operations' => $operations]);

        return $this->answer($response, ['path' => $path, 'operations' => $operations]);
    }

    /** @return list<array<string, mixed>> */
    private function query(AdConnection $c, string $customer, string $gaql): array
    {
        $response = $this->client($c, $customer)->post($this->base($c)."/customers/{$customer}/googleAds:searchStream", ['query' => $gaql]);
        $this->check($response->failed(), $response);

        $rows = [];
        foreach ($response->json() ?? [] as $batch) {
            array_push($rows, ...($batch['results'] ?? []));
        }

        return $rows;
    }

    private function base(AdConnection $c): string
    {
        return 'https://googleads.googleapis.com/'.$c->setting('api_version', 'v20');
    }

    private function client(AdConnection $c, ?string $customer = null): PendingRequest
    {
        $headers = ['developer-token' => (string) $c->credential('developer_token')];

        if ($login = $c->setting('login_customer_id') ?: $c->credential('login_customer_id')) {
            $headers['login-customer-id'] = preg_replace('/\D/', '', (string) $login);
        }

        return Http::withToken($this->accessToken($c))->withHeaders($headers)->acceptJson()->timeout(30);
    }

    private function accessToken(AdConnection $c): string
    {
        return GoogleAdsToken::mint((string) $c->credential('client_id'), (string) $c->credential('client_secret'), (string) $c->credential('refresh_token'));
    }

    private function check(bool $failed, $response): void
    {
        if ($failed) {
            $message = $response->json('error.message') ?? $response->json('0.error.message') ?? 'request failed';
            throw new RuntimeException('Google Ads API error '.$response->status().': '.$message);
        }
    }

    private function status(?string $status): string
    {
        return match (strtoupper((string) $status)) {
            'ENABLED' => 'active',
            'PAUSED' => 'paused',
            'REMOVED' => 'ended',
            default => 'unknown',
        };
    }
}
