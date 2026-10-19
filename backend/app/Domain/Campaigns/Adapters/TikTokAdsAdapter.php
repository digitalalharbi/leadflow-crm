<?php

namespace App\Domain\Campaigns\Adapters;

use App\Domain\Campaigns\AdPlatform;
use App\Models\AdAccount;
use App\Models\AdConnection;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * TikTok Marketing API (v1.3).
 *
 * TikTok answers HTTP 200 for most failures and puts the verdict in `code`
 * (0 is success), so every call is checked on that, not on the status line.
 * Money is in the account's major units. Hierarchy: campaign → ad group → ad.
 */
class TikTokAdsAdapter extends AbstractAdAdapter
{
    private const BASE = 'https://business-api.tiktok.com/open_api/v1.3';

    private const MAX_PAGES = 20;

    protected const SUPPORTED = ['pause', 'resume', 'update_budget', 'create_campaign', 'update_targeting', 'schedule'];

    protected const UNSUPPORTED_BECAUSE = [
        'duplicate_ad_set' => 'platform_has_no_copy',
        'create_ad' => 'needs_media_upload',
        'update_creative' => 'needs_media_upload',
    ];

    public function platform(): string
    {
        return AdPlatform::TikTok->value;
    }

    public function requiredCredentials(): array
    {
        return ['access_token'];
    }

    public function optionalSettings(): array
    {
        return ['account_ids', 'app_id', 'secret'];
    }

    public function accounts(AdConnection $connection): array
    {
        $ids = array_map('strval', (array) $connection->setting('account_ids', []));

        // With the app's id and secret, TikTok lists every advertiser the token can reach.
        if ($ids === [] && $connection->credential('app_id') && $connection->credential('secret')) {
            $data = $this->get($connection, '/oauth2/advertiser/get/', [
                'app_id' => $connection->credential('app_id'), 'secret' => $connection->credential('secret'),
            ]);
            $ids = array_map(fn ($a) => (string) $a['advertiser_id'], $data['list'] ?? []);
        }

        if ($ids === []) {
            throw new RuntimeException('No TikTok advertiser ids: add them to the connection, or the app id and secret.');
        }

        $data = $this->get($connection, '/advertiser/info/', ['advertiser_ids' => json_encode($ids)]);

        return array_map(fn (array $a) => [
            'external_id' => (string) $a['advertiser_id'],
            'name' => $a['name'] ?? null,
            'currency' => $a['currency'] ?? null,
            'status' => str_contains((string) ($a['status'] ?? ''), 'ENABLE') ? 'active' : 'paused',
        ], $data['list'] ?? []);
    }

    public function structure(AdConnection $connection, AdAccount $account): array
    {
        $id = $account->external_id;

        $campaigns = array_map(fn (array $c) => [
            'external_id' => (string) $c['campaign_id'],
            'name' => (string) ($c['campaign_name'] ?? $c['campaign_id']),
            'status' => $this->status($c['operation_status'] ?? null),
            'objective' => $c['objective_type'] ?? null,
            'daily_budget_minor' => ($c['budget_mode'] ?? null) === 'BUDGET_MODE_DAY' ? (int) round(((float) $c['budget']) * 100) : null,
            'lifetime_budget_minor' => ($c['budget_mode'] ?? null) === 'BUDGET_MODE_TOTAL' ? (int) round(((float) $c['budget']) * 100) : null,
        ], $this->list($connection, '/campaign/get/', ['advertiser_id' => $id]));

        $adSets = array_map(fn (array $g) => [
            'external_id' => (string) $g['adgroup_id'],
            'campaign_external_id' => (string) $g['campaign_id'],
            'name' => (string) ($g['adgroup_name'] ?? $g['adgroup_id']),
            'status' => $this->status($g['operation_status'] ?? null),
            'daily_budget_minor' => ($g['budget_mode'] ?? null) === 'BUDGET_MODE_DAY' ? (int) round(((float) $g['budget']) * 100) : null,
            'targeting' => array_filter([
                'location_ids' => $g['location_ids'] ?? null, 'age_groups' => $g['age_groups'] ?? null,
                'gender' => $g['gender'] ?? null, 'languages' => $g['languages'] ?? null,
            ]),
        ], $this->list($connection, '/adgroup/get/', ['advertiser_id' => $id]));

        $ads = array_map(fn (array $a) => [
            'external_id' => (string) $a['ad_id'],
            'campaign_external_id' => (string) $a['campaign_id'],
            'ad_set_external_id' => (string) $a['adgroup_id'],
            'name' => (string) ($a['ad_name'] ?? $a['ad_id']),
            'status' => $this->status($a['operation_status'] ?? null),
            'creative' => [
                'external_id' => isset($a['video_id']) ? 'video:'.$a['video_id'] : null,
                'name' => $a['ad_name'] ?? null,
                'format' => isset($a['video_id']) ? 'video' : (! empty($a['image_ids']) ? 'image' : null),
                'headline' => $a['ad_text'] ?? null,
                'body' => $a['ad_text'] ?? null,
                'call_to_action' => $a['call_to_action'] ?? null,
                'media_url' => null,
                'thumbnail_url' => null,
            ],
        ], $this->list($connection, '/ad/get/', ['advertiser_id' => $id]));

        return ['campaigns' => $campaigns, 'ad_sets' => $adSets, 'ads' => $ads];
    }

    public function metrics(AdConnection $connection, AdAccount $account, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = $this->list($connection, '/report/integrated/get/', [
            'advertiser_id' => $account->external_id,
            'report_type' => 'BASIC',
            'data_level' => 'AUCTION_AD',
            'dimensions' => json_encode(['ad_id', 'stat_time_day']),
            'metrics' => json_encode(['campaign_id', 'adgroup_id', 'spend', 'impressions', 'clicks', 'conversion', 'complete_payment', 'complete_payment_roas']),
            'start_date' => $from->toDateString(),
            'end_date' => $to->toDateString(),
        ]);

        return array_values(array_filter(array_map(function (array $r) use ($account) {
            $m = $r['metrics'] ?? [];
            $spend = (float) ($m['spend'] ?? 0);

            return [
                'level' => 'ad',
                'campaign_external_id' => (string) ($m['campaign_id'] ?? ''),
                'ad_set_external_id' => isset($m['adgroup_id']) ? (string) $m['adgroup_id'] : null,
                'ad_external_id' => (string) ($r['dimensions']['ad_id'] ?? ''),
                'date' => substr((string) ($r['dimensions']['stat_time_day'] ?? ''), 0, 10),
                'spend_minor' => (int) round($spend * 100),
                'impressions' => (int) ($m['impressions'] ?? 0),
                'clicks' => (int) ($m['clicks'] ?? 0),
                // TikTok's "conversion" is the optimisation event — for a lead
                // generation campaign, the form submission.
                'leads' => (int) ($m['conversion'] ?? 0),
                'purchases' => (int) ($m['complete_payment'] ?? 0),
                'revenue_minor' => (int) round($spend * (float) ($m['complete_payment_roas'] ?? 0) * 100),
                'currency' => $account->currency,
            ];
        }, $rows), fn ($r) => $r['campaign_external_id'] !== '' && $r['date'] !== ''));
    }

    public function leadForms(AdConnection $connection, AdAccount $account): ?array
    {
        return array_map(fn (array $p) => [
            'external_id' => (string) $p['page_id'],
            'name' => (string) ($p['title'] ?? $p['page_id']),
            'status' => $p['status'] ?? null,
        ], $this->list($connection, '/page/get/', ['advertiser_id' => $account->external_id, 'business_type' => 'LEAD_GEN']));
    }

    // ------------------------------------------------------------- actions

    protected function pause(AdConnection $c, array $target, array $payload): array
    {
        return $this->setStatus($c, $target, 'DISABLE');
    }

    protected function resume(AdConnection $c, array $target, array $payload): array
    {
        return $this->setStatus($c, $target, 'ENABLE');
    }

    protected function updateBudget(AdConnection $c, array $target, array $payload): array
    {
        $id = $this->requireTarget($target, 'campaign', 'ad_set');
        $budget = round(((int) ($payload['daily_budget_minor'] ?? 0)) / 100, 2);
        $account = $this->requireAccount($target);

        return $target['level'] === 'campaign'
            ? $this->post($c, '/campaign/update/', ['advertiser_id' => $account, 'campaign_id' => $id, 'budget' => $budget])
            : $this->post($c, '/adgroup/budget/update/', ['advertiser_id' => $account, 'budget' => [['adgroup_id' => $id, 'budget' => $budget]]]);
    }

    protected function createCampaign(AdConnection $c, array $target, array $payload): array
    {
        return $this->post($c, '/campaign/create/', [
            'advertiser_id' => $this->requireAccount($target),
            'campaign_name' => (string) ($payload['name'] ?? 'Campaign'),
            'objective_type' => (string) ($payload['objective'] ?? 'LEAD_GENERATION'),
            'budget_mode' => 'BUDGET_MODE_INFINITE',
            'operation_status' => 'DISABLE',
        ], 'campaign_id');
    }

    protected function updateTargeting(AdConnection $c, array $target, array $payload): array
    {
        if (! is_array($payload['targeting'] ?? null)) {
            throw new RuntimeException('Targeting must be given as TikTok ad group fields.');
        }

        return $this->post($c, '/adgroup/update/', ['advertiser_id' => $this->requireAccount($target), 'adgroup_id' => $this->requireTarget($target, 'ad_set')] + $payload['targeting']);
    }

    protected function schedule(AdConnection $c, array $target, array $payload): array
    {
        return $this->post($c, '/adgroup/update/', array_filter([
            'advertiser_id' => $this->requireAccount($target),
            'adgroup_id' => $this->requireTarget($target, 'ad_set'),
            'schedule_type' => 'SCHEDULE_START_END',
            'schedule_start_time' => isset($payload['starts_at']) ? date('Y-m-d H:i:s', strtotime($payload['starts_at'])) : null,
            'schedule_end_time' => isset($payload['ends_at']) ? date('Y-m-d H:i:s', strtotime($payload['ends_at'])) : null,
        ]));
    }

    // ------------------------------------------------------------- plumbing

    private function setStatus(AdConnection $c, array $target, string $status): array
    {
        $id = $this->requireTarget($target, 'campaign', 'ad_set', 'ad');
        [$path, $key] = match ($target['level']) {
            'campaign' => ['/campaign/status/update/', 'campaign_ids'],
            'ad_set' => ['/adgroup/status/update/', 'adgroup_ids'],
            'ad' => ['/ad/status/update/', 'ad_ids'],
        };

        return $this->post($c, $path, ['advertiser_id' => $this->requireAccount($target), $key => [$id], 'operation_status' => $status]);
    }

    private function client(AdConnection $c): PendingRequest
    {
        return Http::withHeaders(['Access-Token' => (string) $c->credential('access_token')])->acceptJson()->timeout(30);
    }

    private function post(AdConnection $c, string $path, array $body, ?string $createdKey = null): array
    {
        $response = $this->client($c)->post(self::BASE.$path, $body);
        $code = (int) $response->json('code', -1);

        return $this->answer($response, ['path' => $path, 'body' => $body], $createdKey ? (string) $response->json('data.'.$createdKey) : null, $code === 0 ? null : (string) $response->json('message', 'TikTok refused the change'));
    }

    private function get(AdConnection $c, string $path, array $query): array
    {
        return $this->checked($this->client($c)->get(self::BASE.$path, $query));
    }

    /** @return list<array<string, mixed>> */
    private function list(AdConnection $c, string $path, array $query): array
    {
        $rows = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $data = $this->get($c, $path, $query + ['page' => $page, 'page_size' => 1000]);
            array_push($rows, ...($data['list'] ?? []));

            if ($page >= (int) ($data['page_info']['total_page'] ?? 1)) {
                break;
            }
        }

        return $rows;
    }

    private function checked(Response $response): array
    {
        if ($response->failed() || (int) $response->json('code', -1) !== 0) {
            throw new RuntimeException('TikTok API error '.$response->json('code', $response->status()).': '.$response->json('message', 'request failed'));
        }

        return $response->json('data') ?? [];
    }

    private function status(?string $operation): string
    {
        return match (strtoupper((string) $operation)) {
            'ENABLE' => 'active',
            'DISABLE' => 'paused',
            'DELETE' => 'ended',
            default => 'unknown',
        };
    }
}
