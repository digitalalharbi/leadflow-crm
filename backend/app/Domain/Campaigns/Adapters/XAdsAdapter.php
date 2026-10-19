<?php

namespace App\Domain\Campaigns\Adapters;

use App\Domain\Campaigns\AdPlatform;
use App\Models\AdAccount;
use App\Models\AdConnection;
use App\Support\OAuth1;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * X Ads API (v12), OAuth 1.0a user context.
 *
 * Hierarchy: campaign → line item → promoted post. Money is in local micros.
 * Stats come per promoted post, twenty ids at a time, as day-aligned arrays.
 * X has no lead forms in its Ads API, which the screen says rather than hides.
 */
class XAdsAdapter extends AbstractAdAdapter
{
    private const BASE = 'https://ads-api.x.com/12';

    private const MAX_PAGES = 20;

    protected const SUPPORTED = ['pause', 'resume', 'update_budget', 'create_campaign', 'schedule'];

    protected const UNSUPPORTED_BECAUSE = [
        'duplicate_ad_set' => 'platform_has_no_copy',
        'create_ad' => 'needs_post_composer',
        'update_creative' => 'posts_are_immutable',
        'update_targeting' => 'criteria_not_built',
    ];

    public function platform(): string
    {
        return AdPlatform::X->value;
    }

    public function requiredCredentials(): array
    {
        return ['consumer_key', 'consumer_secret', 'access_token', 'access_token_secret'];
    }

    public function optionalSettings(): array
    {
        return ['account_ids', 'funding_instrument_id'];
    }

    public function accounts(AdConnection $connection): array
    {
        $only = array_map('strval', (array) $connection->setting('account_ids', []));

        return array_values(array_filter(array_map(fn ($a) => [
            'external_id' => (string) $a['id'],
            'name' => $a['name'] ?? null,
            'currency' => null,
            'status' => ($a['approval_status'] ?? 'ACCEPTED') === 'ACCEPTED' ? 'active' : 'paused',
        ], $this->paged($connection, '/accounts')), fn ($a) => $only === [] || in_array($a['external_id'], $only, true)));
    }

    public function structure(AdConnection $connection, AdAccount $account): array
    {
        $id = $account->external_id;

        $campaigns = array_map(fn ($c) => [
            'external_id' => (string) $c['id'],
            'name' => (string) $c['name'],
            'status' => $this->status($c['entity_status'] ?? null),
            'objective' => null,
            'daily_budget_minor' => self::minorFromMicros($c['daily_budget_amount_local_micro'] ?? null),
            'lifetime_budget_minor' => self::minorFromMicros($c['total_budget_amount_local_micro'] ?? null),
            'starts_at' => $c['start_time'] ?? null,
            'ends_at' => $c['end_time'] ?? null,
        ], $this->paged($connection, "/accounts/{$id}/campaigns"));

        $lineItems = $this->paged($connection, "/accounts/{$id}/line_items");
        $lineCampaign = collect($lineItems)->mapWithKeys(fn ($l) => [(string) $l['id'] => (string) $l['campaign_id']]);

        $adSets = array_map(fn ($l) => [
            'external_id' => (string) $l['id'],
            'campaign_external_id' => (string) $l['campaign_id'],
            'name' => (string) ($l['name'] ?? $l['id']),
            'status' => $this->status($l['entity_status'] ?? null),
            'daily_budget_minor' => null,
        ], $lineItems);

        $ads = array_map(fn ($p) => [
            'external_id' => (string) $p['id'],
            'campaign_external_id' => $lineCampaign->get((string) $p['line_item_id'], ''),
            'ad_set_external_id' => (string) $p['line_item_id'],
            'name' => 'Post '.$p['tweet_id'],
            'status' => $this->status($p['entity_status'] ?? null),
            'creative' => ['external_id' => 'post:'.$p['tweet_id'], 'name' => null, 'format' => 'text', 'headline' => null, 'body' => null, 'call_to_action' => null, 'media_url' => null, 'thumbnail_url' => null],
        ], $this->paged($connection, "/accounts/{$id}/promoted_tweets"));

        return ['campaigns' => $campaigns, 'ad_sets' => $adSets, 'ads' => $ads];
    }

    public function metrics(AdConnection $connection, AdAccount $account, CarbonInterface $from, CarbonInterface $to): array
    {
        $ads = collect($this->structure($connection, $account)['ads'])->keyBy('external_id');
        $rows = [];

        foreach ($ads->keys()->chunk(20) as $chunk) {
            $data = $this->request($connection, 'GET', "/stats/accounts/{$account->external_id}", [
                'entity' => 'PROMOTED_TWEET',
                'entity_ids' => $chunk->implode(','),
                'start_time' => $from->toDateString(),
                'end_time' => $to->copy()->addDay()->toDateString(),
                'granularity' => 'DAY',
                'placement' => 'ALL_ON_TWITTER',
                'metric_groups' => 'ENGAGEMENT,BILLING,WEB_CONVERSION',
            ]);

            foreach ($data['data'] ?? [] as $entity) {
                $ad = $ads->get((string) $entity['id']);
                $m = $entity['id_data'][0]['metrics'] ?? [];
                $days = count($m['impressions'] ?? []);

                for ($i = 0; $i < $days; $i++) {
                    $rows[] = [
                        'level' => 'ad',
                        'campaign_external_id' => $ad['campaign_external_id'] ?? '',
                        'ad_set_external_id' => $ad['ad_set_external_id'] ?? null,
                        'ad_external_id' => (string) $entity['id'],
                        'date' => $from->copy()->addDays($i)->toDateString(),
                        'spend_minor' => self::minorFromMicros($m['billed_charge_local_micro'][$i] ?? 0) ?? 0,
                        'impressions' => (int) ($m['impressions'][$i] ?? 0),
                        'clicks' => (int) ($m['clicks'][$i] ?? 0),
                        'leads' => (int) ($m['conversion_sign_ups']['metric'][$i] ?? 0),
                        'purchases' => (int) ($m['conversion_purchases']['metric'][$i] ?? 0),
                        'revenue_minor' => self::minorFromMicros($m['conversion_purchases']['sale_amount'][$i] ?? 0) ?? 0,
                        'currency' => $account->currency,
                    ];
                }
            }
        }

        return array_values(array_filter($rows, fn ($r) => $r['campaign_external_id'] !== ''));
    }

    // ------------------------------------------------------------- actions

    protected function pause(AdConnection $c, array $target, array $payload): array
    {
        return $this->put($c, $target, ['entity_status' => 'PAUSED'], 'campaign', 'ad_set');
    }

    protected function resume(AdConnection $c, array $target, array $payload): array
    {
        return $this->put($c, $target, ['entity_status' => 'ACTIVE'], 'campaign', 'ad_set');
    }

    protected function updateBudget(AdConnection $c, array $target, array $payload): array
    {
        return $this->put($c, $target, ['daily_budget_amount_local_micro' => ((int) ($payload['daily_budget_minor'] ?? 0)) * 10_000], 'campaign');
    }

    protected function schedule(AdConnection $c, array $target, array $payload): array
    {
        return $this->put($c, $target, array_filter(['start_time' => $payload['starts_at'] ?? null, 'end_time' => $payload['ends_at'] ?? null]), 'ad_set');
    }

    protected function createCampaign(AdConnection $c, array $target, array $payload): array
    {
        $account = $this->requireAccount($target);
        $funding = $payload['funding_instrument_id'] ?? $c->setting('funding_instrument_id');

        if (! $funding) {
            throw new RuntimeException('A new X campaign needs a funding instrument.');
        }

        $params = [
            'name' => (string) ($payload['name'] ?? 'Campaign'),
            'funding_instrument_id' => (string) $funding,
            'daily_budget_amount_local_micro' => (string) (((int) ($payload['daily_budget_minor'] ?? 1000)) * 10_000),
            'entity_status' => 'PAUSED',
        ];

        return $this->write($c, 'POST', "/accounts/{$account}/campaigns", $params, 'data.id');
    }

    // ------------------------------------------------------------- plumbing

    private function put(AdConnection $c, array $target, array $params, string ...$levels): array
    {
        $id = $this->requireTarget($target, ...$levels);
        $collection = $target['level'] === 'campaign' ? 'campaigns' : 'line_items';

        return $this->write($c, 'PUT', '/accounts/'.$this->requireAccount($target)."/{$collection}/{$id}", $params);
    }

    private function write(AdConnection $c, string $method, string $path, array $params, ?string $createdKey = null): array
    {
        $url = self::BASE.$path;
        $response = Http::withHeaders(['Authorization' => $this->authorization($c, $method, $url, $params)])
            ->acceptJson()->timeout(30)->send($method, $url.'?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986));

        return $this->answer($response, ['method' => $method, 'path' => $path, 'params' => $params], $createdKey ? (string) $response->json($createdKey) : null, $response->failed() ? (string) $response->json('errors.0.message', 'X refused the change') : null);
    }

    private function request(AdConnection $c, string $method, string $path, array $params = []): array
    {
        $url = self::BASE.$path;
        $response = Http::withHeaders(['Authorization' => $this->authorization($c, $method, $url, $params)])
            ->acceptJson()->timeout(30)->send($method, $url.($params ? '?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986) : ''));

        if ($response->failed()) {
            throw new RuntimeException('X Ads API error '.$response->status().': '.$response->json('errors.0.message', 'request failed'));
        }

        return $response->json() ?? [];
    }

    /** @return list<array<string, mixed>> */
    private function paged(AdConnection $c, string $path): array
    {
        $rows = [];
        $cursor = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $data = $this->request($c, 'GET', $path, array_filter(['count' => 1000, 'cursor' => $cursor]));
            array_push($rows, ...($data['data'] ?? []));
            $cursor = $data['next_cursor'] ?? null;

            if (! $cursor) {
                break;
            }
        }

        return $rows;
    }

    private function authorization(AdConnection $c, string $method, string $url, array $params): string
    {
        return OAuth1::header(
            $method, $url, array_map('strval', $params),
            (string) $c->credential('consumer_key'), (string) $c->credential('consumer_secret'),
            (string) $c->credential('access_token'), (string) $c->credential('access_token_secret'),
        );
    }

    private function status(?string $status): string
    {
        return match (strtoupper((string) $status)) {
            'ACTIVE' => 'active',
            'PAUSED', 'DRAFT' => 'paused',
            'DELETED' => 'ended',
            default => 'unknown',
        };
    }
}
