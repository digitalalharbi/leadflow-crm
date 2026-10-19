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
 * Snapchat Marketing API (v1).
 *
 * Snap updates whole objects: a PUT carries the entire campaign or ad squad,
 * so every write reads the current object first and changes only the named
 * field. Money is in micros. Hierarchy: campaign → ad squad → ad.
 */
class SnapchatAdsAdapter extends AbstractAdAdapter
{
    private const BASE = 'https://adsapi.snapchat.com/v1';

    private const MAX_PAGES = 20;

    protected const SUPPORTED = ['pause', 'resume', 'update_budget', 'create_campaign', 'update_targeting', 'schedule'];

    protected const UNSUPPORTED_BECAUSE = [
        'duplicate_ad_set' => 'platform_has_no_copy',
        'create_ad' => 'needs_media_upload',
        'update_creative' => 'needs_media_upload',
    ];

    /** @var array<string, string> refreshed tokens for this run, by connection */
    private array $tokens = [];

    public function platform(): string
    {
        return AdPlatform::Snapchat->value;
    }

    public function requiredCredentials(): array
    {
        return ['access_token'];
    }

    public function optionalSettings(): array
    {
        return ['account_ids', 'refresh_token', 'client_id', 'client_secret'];
    }

    public function accounts(AdConnection $connection): array
    {
        $only = array_map('strval', (array) $connection->setting('account_ids', []));
        $orgs = $this->get($connection, '/me/organizations', ['with_ad_accounts' => 'true'])['organizations'] ?? [];
        $accounts = [];

        foreach ($orgs as $org) {
            foreach ($org['organization']['ad_accounts'] ?? [] as $a) {
                if ($only === [] || in_array((string) $a['id'], $only, true)) {
                    $accounts[] = [
                        'external_id' => (string) $a['id'],
                        'name' => $a['name'] ?? null,
                        'currency' => $a['currency'] ?? null,
                        'status' => ($a['status'] ?? 'ACTIVE') === 'ACTIVE' ? 'active' : 'paused',
                    ];
                }
            }
        }

        return $accounts;
    }

    public function structure(AdConnection $connection, AdAccount $account): array
    {
        $id = $account->external_id;

        $campaigns = array_map(fn ($w) => [
            'external_id' => (string) $w['campaign']['id'],
            'name' => (string) $w['campaign']['name'],
            'status' => $this->status($w['campaign']['status'] ?? null),
            'objective' => $w['campaign']['objective'] ?? null,
            'daily_budget_minor' => self::minorFromMicros($w['campaign']['daily_budget_micro'] ?? null),
            'lifetime_budget_minor' => self::minorFromMicros($w['campaign']['lifetime_spend_cap_micro'] ?? null),
            'starts_at' => $w['campaign']['start_time'] ?? null,
            'ends_at' => $w['campaign']['end_time'] ?? null,
        ], $this->paged($connection, "/adaccounts/{$id}/campaigns", 'campaigns'));

        $squads = $this->paged($connection, "/adaccounts/{$id}/adsquads", 'adsquads');
        $squadCampaign = [];

        $adSets = array_map(function ($w) use (&$squadCampaign) {
            $s = $w['adsquad'];
            $squadCampaign[(string) $s['id']] = (string) $s['campaign_id'];

            return [
                'external_id' => (string) $s['id'],
                'campaign_external_id' => (string) $s['campaign_id'],
                'name' => (string) $s['name'],
                'status' => $this->status($s['status'] ?? null),
                'daily_budget_minor' => self::minorFromMicros($s['daily_budget_micro'] ?? null),
                'targeting' => $s['targeting'] ?? null,
            ];
        }, $squads);

        $creatives = collect($this->paged($connection, "/adaccounts/{$id}/creatives", 'creatives'))
            ->mapWithKeys(fn ($w) => [(string) $w['creative']['id'] => $w['creative']]);

        $ads = array_map(function ($w) use ($squadCampaign, $creatives) {
            $a = $w['ad'];
            $creative = $creatives->get((string) ($a['creative_id'] ?? ''));

            return [
                'external_id' => (string) $a['id'],
                'campaign_external_id' => $squadCampaign[(string) $a['ad_squad_id']] ?? '',
                'ad_set_external_id' => (string) $a['ad_squad_id'],
                'name' => (string) $a['name'],
                'status' => $this->status($a['status'] ?? null),
                'creative' => $creative ? [
                    'external_id' => (string) $creative['id'],
                    'name' => $creative['name'] ?? null,
                    'format' => str_contains(strtoupper((string) ($creative['type'] ?? '')), 'VIDEO') ? 'video' : 'image',
                    'headline' => $creative['headline'] ?? null,
                    'body' => $creative['brand_name'] ?? null,
                    'call_to_action' => $creative['call_to_action'] ?? null,
                    'media_url' => null,
                    'thumbnail_url' => null,
                ] : null,
            ];
        }, $this->paged($connection, "/adaccounts/{$id}/ads", 'ads'));

        return ['campaigns' => $campaigns, 'ad_sets' => $adSets, 'ads' => $ads];
    }

    public function metrics(AdConnection $connection, AdAccount $account, CarbonInterface $from, CarbonInterface $to): array
    {
        $structure = $this->structure($connection, $account);
        $adIndex = collect($structure['ads'])->keyBy('external_id');

        $data = $this->get($connection, "/adaccounts/{$account->external_id}/stats", [
            'granularity' => 'DAY',
            'breakdown' => 'ad',
            'start_time' => $from->toDateString().'T00:00:00Z',
            'end_time' => $to->copy()->addDay()->toDateString().'T00:00:00Z',
            'fields' => 'impressions,swipes,spend,conversion_purchases,conversion_purchases_value,conversion_sign_ups',
        ]);

        $rows = [];
        foreach ($data['timeseries_stats'] ?? [] as $wrapper) {
            foreach ($wrapper['timeseries_stat']['breakdown_stats']['ad'] ?? [] as $ad) {
                $known = $adIndex->get((string) $ad['id']);
                foreach ($ad['timeseries'] ?? [] as $day) {
                    $s = $day['stats'] ?? [];
                    $rows[] = [
                        'level' => 'ad',
                        'campaign_external_id' => $known['campaign_external_id'] ?? '',
                        'ad_set_external_id' => $known['ad_set_external_id'] ?? null,
                        'ad_external_id' => (string) $ad['id'],
                        'date' => substr((string) $day['start_time'], 0, 10),
                        'spend_minor' => self::minorFromMicros($s['spend'] ?? 0) ?? 0,
                        'impressions' => (int) ($s['impressions'] ?? 0),
                        // A swipe up is Snapchat's click.
                        'clicks' => (int) ($s['swipes'] ?? 0),
                        'leads' => (int) ($s['conversion_sign_ups'] ?? 0),
                        'purchases' => (int) ($s['conversion_purchases'] ?? 0),
                        'revenue_minor' => self::minorFromMicros($s['conversion_purchases_value'] ?? 0) ?? 0,
                        'currency' => $account->currency,
                    ];
                }
            }
        }

        return array_values(array_filter($rows, fn ($r) => $r['campaign_external_id'] !== ''));
    }

    public function leadForms(AdConnection $connection, AdAccount $account): ?array
    {
        return array_map(fn ($w) => [
            'external_id' => (string) $w['lead_generation_form']['id'],
            'name' => (string) ($w['lead_generation_form']['name'] ?? $w['lead_generation_form']['id']),
            'status' => $w['lead_generation_form']['status'] ?? null,
        ], $this->paged($connection, "/adaccounts/{$account->external_id}/lead_generation_forms", 'lead_generation_forms'));
    }

    // ------------------------------------------------------------- actions

    protected function pause(AdConnection $c, array $target, array $payload): array
    {
        return $this->change($c, $target, ['status' => 'PAUSED'], 'campaign', 'ad_set', 'ad');
    }

    protected function resume(AdConnection $c, array $target, array $payload): array
    {
        return $this->change($c, $target, ['status' => 'ACTIVE'], 'campaign', 'ad_set', 'ad');
    }

    protected function updateBudget(AdConnection $c, array $target, array $payload): array
    {
        return $this->change($c, $target, ['daily_budget_micro' => ((int) ($payload['daily_budget_minor'] ?? 0)) * 10_000], 'campaign', 'ad_set');
    }

    protected function schedule(AdConnection $c, array $target, array $payload): array
    {
        return $this->change($c, $target, array_filter([
            'start_time' => $payload['starts_at'] ?? null,
            'end_time' => $payload['ends_at'] ?? null,
        ]), 'campaign', 'ad_set');
    }

    protected function updateTargeting(AdConnection $c, array $target, array $payload): array
    {
        if (! is_array($payload['targeting'] ?? null)) {
            throw new RuntimeException('Targeting must be given as a Snapchat targeting object.');
        }

        return $this->change($c, $target, ['targeting' => $payload['targeting']], 'ad_set');
    }

    protected function createCampaign(AdConnection $c, array $target, array $payload): array
    {
        $account = $this->requireAccount($target);
        $body = ['campaigns' => [[
            'name' => (string) ($payload['name'] ?? 'Campaign'),
            'ad_account_id' => $account,
            'status' => 'PAUSED',
            'start_time' => $payload['starts_at'] ?? now()->toIso8601String(),
        ]]];

        $response = $this->client($c)->post(self::BASE."/adaccounts/{$account}/campaigns", $body);

        return $this->answer($response, ['body' => $body], (string) ($response->json('campaigns.0.campaign.id') ?? ''), $this->snapError($response));
    }

    // ------------------------------------------------------------- plumbing

    /** Read the whole object, change the named fields, put the whole object back. */
    private function change(AdConnection $c, array $target, array $fields, string ...$levels): array
    {
        $id = $this->requireTarget($target, ...$levels);
        [$read, $key] = match ($target['level']) {
            'campaign' => ["/campaigns/{$id}", 'campaign'],
            'ad_set' => ["/adsquads/{$id}", 'adsquad'],
            'ad' => ["/ads/{$id}", 'ad'],
        };

        $current = $this->get($c, $read, [])[$key.'s'][0][$key] ?? null;

        if ($current === null) {
            throw new RuntimeException('Snapchat did not return the object to change.');
        }

        $object = array_merge($current, $fields);
        $write = match ($target['level']) {
            'campaign' => '/adaccounts/'.$this->requireAccount($target).'/campaigns',
            'ad_set' => '/campaigns/'.$current['campaign_id'].'/adsquads',
            'ad' => '/adsquads/'.$current['ad_squad_id'].'/ads',
        };
        $body = [$key.'s' => [$object]];

        $response = $this->client($c)->put(self::BASE.$write, $body);

        return $this->answer($response, ['path' => $write, 'changed' => $fields], null, $this->snapError($response));
    }

    private function snapError(Response $response): ?string
    {
        if ($response->failed()) {
            return (string) ($response->json('debug_message') ?? $response->json('display_message') ?? 'Snapchat refused the change');
        }

        return $response->json('request_status') === 'ERROR' ? (string) $response->json('debug_message', 'Snapchat refused the change') : null;
    }

    private function client(AdConnection $c): PendingRequest
    {
        return Http::withToken($this->token($c))->acceptJson()->timeout(30);
    }

    /** Snap tokens last thirty minutes; with a refresh token one is minted per run. */
    private function token(AdConnection $c): string
    {
        $key = $c->uuid ?? 'standin';

        if (isset($this->tokens[$key])) {
            return $this->tokens[$key];
        }

        $token = (string) $c->credential('access_token');

        if ($c->credential('refresh_token') && $c->credential('client_id') && $c->credential('client_secret')) {
            $response = Http::asForm()->timeout(20)->post('https://accounts.snapchat.com/login/oauth2/access_token', [
                'refresh_token' => $c->credential('refresh_token'),
                'client_id' => $c->credential('client_id'),
                'client_secret' => $c->credential('client_secret'),
                'grant_type' => 'refresh_token',
            ]);

            if ($response->successful() && $response->json('access_token')) {
                $token = (string) $response->json('access_token');
            }
        }

        return $this->tokens[$key] = $token;
    }

    private function get(AdConnection $c, string $path, array $query): array
    {
        $response = $this->client($c)->get(str_starts_with($path, 'http') ? $path : self::BASE.$path, $query);

        if ($response->failed() || $response->json('request_status') === 'ERROR') {
            throw new RuntimeException('Snapchat API error '.$response->status().': '.$response->json('debug_message', 'request failed'));
        }

        return $response->json() ?? [];
    }

    /** @return list<array<string, mixed>> */
    private function paged(AdConnection $c, string $path, string $key): array
    {
        $rows = [];
        $next = $path;

        for ($page = 0; $page < self::MAX_PAGES && $next; $page++) {
            $data = $this->get($c, $next, []);
            array_push($rows, ...($data[$key] ?? []));
            $next = $data['paging']['next_link'] ?? null;
        }

        return $rows;
    }

    private function status(?string $status): string
    {
        return match (strtoupper((string) $status)) {
            'ACTIVE' => 'active',
            'PAUSED' => 'paused',
            'DELETED' => 'ended',
            default => 'unknown',
        };
    }
}
