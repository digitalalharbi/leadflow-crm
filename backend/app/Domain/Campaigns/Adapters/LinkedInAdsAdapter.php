<?php

namespace App\Domain\Campaigns\Adapters;

use App\Domain\Campaigns\AdPlatform;
use App\Models\AdAccount;
use App\Models\AdConnection;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * LinkedIn Marketing API (versioned REST).
 *
 * LinkedIn's names do not line up with everybody else's, so they are mapped:
 *
 *   LinkedIn campaign group  →  our campaign
 *   LinkedIn campaign        →  our ad set   (where budget and targeting live)
 *   LinkedIn creative        →  our ad
 *
 * Writes are PARTIAL_UPDATE patches that $set only the named field. Rest.li
 * query syntax — List(), (key:value) — is built by hand, because a general URL
 * encoder would escape the parentheses LinkedIn needs to see.
 */
class LinkedInAdsAdapter extends AbstractAdAdapter
{
    private const BASE = 'https://api.linkedin.com/rest';

    private const MAX_PAGES = 20;

    protected const SUPPORTED = ['pause', 'resume', 'update_budget', 'create_campaign', 'update_targeting', 'schedule'];

    protected const UNSUPPORTED_BECAUSE = [
        'duplicate_ad_set' => 'platform_has_no_copy',
        'create_ad' => 'needs_post_composer',
        'update_creative' => 'posts_are_immutable',
    ];

    public function platform(): string
    {
        return AdPlatform::LinkedIn->value;
    }

    public function requiredCredentials(): array
    {
        return ['access_token'];
    }

    public function optionalSettings(): array
    {
        return ['account_ids', 'api_version'];
    }

    public function accounts(AdConnection $connection): array
    {
        $only = array_map('strval', (array) $connection->setting('account_ids', []));

        return array_values(array_filter(array_map(fn ($a) => [
            'external_id' => (string) $a['id'],
            'name' => $a['name'] ?? null,
            'currency' => $a['currency'] ?? null,
            'status' => ($a['status'] ?? 'ACTIVE') === 'ACTIVE' ? 'active' : 'paused',
        ], $this->paged($connection, '/adAccounts?q=search')), fn ($a) => $only === [] || in_array($a['external_id'], $only, true)));
    }

    public function structure(AdConnection $connection, AdAccount $account): array
    {
        $id = $account->external_id;

        $campaigns = array_map(fn ($g) => [
            'external_id' => (string) $g['id'],
            'name' => (string) $g['name'],
            'status' => $this->status($g['status'] ?? null),
            'objective' => null,
            'daily_budget_minor' => null,
            'lifetime_budget_minor' => isset($g['totalBudget']['amount']) ? (int) round(((float) $g['totalBudget']['amount']) * 100) : null,
            'starts_at' => isset($g['runSchedule']['start']) ? date(DATE_ATOM, (int) ($g['runSchedule']['start'] / 1000)) : null,
            'ends_at' => isset($g['runSchedule']['end']) ? date(DATE_ATOM, (int) ($g['runSchedule']['end'] / 1000)) : null,
        ], $this->paged($connection, "/adAccounts/{$id}/adCampaignGroups?q=search"));

        $linkedCampaigns = $this->paged($connection, "/adAccounts/{$id}/adCampaigns?q=search");
        $groupOf = [];

        $adSets = array_map(function ($c) use (&$groupOf) {
            $group = self::idFromUrn($c['campaignGroup'] ?? '');
            $groupOf[(string) $c['id']] = $group;

            return [
                'external_id' => (string) $c['id'],
                'campaign_external_id' => $group,
                'name' => (string) $c['name'],
                'status' => $this->status($c['status'] ?? null),
                'daily_budget_minor' => isset($c['dailyBudget']['amount']) ? (int) round(((float) $c['dailyBudget']['amount']) * 100) : null,
                'targeting' => $c['targetingCriteria'] ?? null,
            ];
        }, $linkedCampaigns);

        $ads = array_map(function ($cr) use ($groupOf) {
            $campaign = self::idFromUrn($cr['campaign'] ?? '');

            return [
                'external_id' => self::idFromUrn((string) $cr['id']),
                'campaign_external_id' => $groupOf[$campaign] ?? '',
                'ad_set_external_id' => $campaign,
                'name' => (string) ($cr['name'] ?? self::idFromUrn((string) $cr['id'])),
                'status' => $this->status($cr['intendedStatus'] ?? null),
                'creative' => ['external_id' => (string) $cr['id'], 'name' => $cr['name'] ?? null, 'format' => null, 'headline' => null, 'body' => null, 'call_to_action' => null, 'media_url' => null, 'thumbnail_url' => null],
            ];
        }, $this->paged($connection, "/adAccounts/{$id}/creatives?q=criteria"));

        return ['campaigns' => $campaigns, 'ad_sets' => $adSets, 'ads' => $ads];
    }

    public function metrics(AdConnection $connection, AdAccount $account, CarbonInterface $from, CarbonInterface $to): array
    {
        $ads = collect($this->structure($connection, $account)['ads'])->keyBy('external_id');
        $range = sprintf('(start:(year:%d,month:%d,day:%d),end:(year:%d,month:%d,day:%d))', $from->year, $from->month, $from->day, $to->year, $to->month, $to->day);
        $accountUrn = rawurlencode('urn:li:sponsoredAccount:'.$account->external_id);

        $elements = $this->get($connection, "/adAnalytics?q=analytics&pivot=CREATIVE&timeGranularity=DAILY&dateRange={$range}&accounts=List({$accountUrn})"
            .'&fields=costInLocalCurrency,impressions,clicks,oneClickLeads,externalWebsiteConversions,externalWebsitePostClickConversions,dateRange,pivotValues')['elements'] ?? [];

        return array_values(array_filter(array_map(function ($e) use ($ads, $account) {
            $creative = self::idFromUrn($e['pivotValues'][0] ?? '');
            $ad = $ads->get($creative);
            $d = $e['dateRange']['start'] ?? null;

            return [
                'level' => 'ad',
                'campaign_external_id' => $ad['campaign_external_id'] ?? '',
                'ad_set_external_id' => $ad['ad_set_external_id'] ?? null,
                'ad_external_id' => $creative,
                'date' => $d ? sprintf('%04d-%02d-%02d', $d['year'], $d['month'], $d['day']) : '',
                'spend_minor' => (int) round(((float) ($e['costInLocalCurrency'] ?? 0)) * 100),
                'impressions' => (int) ($e['impressions'] ?? 0),
                'clicks' => (int) ($e['clicks'] ?? 0),
                // Lead Gen Form submissions on LinkedIn itself.
                'leads' => (int) ($e['oneClickLeads'] ?? 0),
                'purchases' => (int) ($e['externalWebsiteConversions'] ?? 0),
                'revenue_minor' => 0,
                'currency' => $account->currency,
            ];
        }, $elements), fn ($r) => $r['campaign_external_id'] !== '' && $r['date'] !== ''));
    }

    public function leadForms(AdConnection $connection, AdAccount $account): ?array
    {
        $owner = '(sponsoredAccount:'.rawurlencode('urn:li:sponsoredAccount:'.$account->external_id).')';

        return array_map(fn ($f) => [
            'external_id' => (string) $f['id'],
            'name' => (string) ($f['name'] ?? $f['id']),
            'status' => $f['state'] ?? null,
        ], $this->paged($connection, "/leadForms?q=owner&owner={$owner}"));
    }

    // ------------------------------------------------------------- actions

    protected function pause(AdConnection $c, array $target, array $payload): array
    {
        return $this->patch($c, $target, $target['level'] === 'ad' ? ['intendedStatus' => 'PAUSED'] : ['status' => 'PAUSED']);
    }

    protected function resume(AdConnection $c, array $target, array $payload): array
    {
        return $this->patch($c, $target, $target['level'] === 'ad' ? ['intendedStatus' => 'ACTIVE'] : ['status' => 'ACTIVE']);
    }

    protected function updateBudget(AdConnection $c, array $target, array $payload): array
    {
        $this->requireTarget($target, 'ad_set');

        return $this->patch($c, $target, ['dailyBudget' => [
            'amount' => number_format(((int) ($payload['daily_budget_minor'] ?? 0)) / 100, 2, '.', ''),
            'currencyCode' => (string) ($payload['currency'] ?? 'USD'),
        ]]);
    }

    protected function schedule(AdConnection $c, array $target, array $payload): array
    {
        $this->requireTarget($target, 'campaign', 'ad_set');

        return $this->patch($c, $target, ['runSchedule' => array_filter([
            'start' => isset($payload['starts_at']) ? strtotime($payload['starts_at']) * 1000 : null,
            'end' => isset($payload['ends_at']) ? strtotime($payload['ends_at']) * 1000 : null,
        ])]);
    }

    protected function updateTargeting(AdConnection $c, array $target, array $payload): array
    {
        $this->requireTarget($target, 'ad_set');

        if (! is_array($payload['targeting'] ?? null)) {
            throw new RuntimeException('Targeting must be given as LinkedIn targetingCriteria.');
        }

        return $this->patch($c, $target, ['targetingCriteria' => $payload['targeting']]);
    }

    /** A new campaign group, created as a draft — LinkedIn's word for not running. */
    protected function createCampaign(AdConnection $c, array $target, array $payload): array
    {
        $account = $this->requireAccount($target);
        $body = [
            'account' => 'urn:li:sponsoredAccount:'.$account,
            'name' => (string) ($payload['name'] ?? 'Campaign'),
            'status' => 'DRAFT',
            'runSchedule' => ['start' => (isset($payload['starts_at']) ? strtotime($payload['starts_at']) : time()) * 1000],
        ];

        $response = $this->client($c)->post(self::BASE."/adAccounts/{$account}/adCampaignGroups", $body);

        return $this->answer($response, ['body' => $body], self::idFromUrn((string) $response->header('x-restli-id')) ?: null);
    }

    // ------------------------------------------------------------- plumbing

    private function patch(AdConnection $c, array $target, array $set): array
    {
        $id = $this->requireTarget($target, 'campaign', 'ad_set', 'ad');
        $account = $this->requireAccount($target);
        $path = match ($target['level']) {
            'campaign' => "/adAccounts/{$account}/adCampaignGroups/{$id}",
            'ad_set' => "/adAccounts/{$account}/adCampaigns/{$id}",
            'ad' => "/adAccounts/{$account}/creatives/".rawurlencode('urn:li:sponsoredCreative:'.$id),
        };
        $body = ['patch' => ['$set' => $set]];

        $response = $this->client($c)->withHeaders(['X-RestLi-Method' => 'PARTIAL_UPDATE'])->post(self::BASE.$path, $body);

        return $this->answer($response, ['path' => $path, 'body' => $body]);
    }

    private function client(AdConnection $c): PendingRequest
    {
        return Http::withToken((string) $c->credential('access_token'))
            ->withHeaders(['LinkedIn-Version' => (string) $c->setting('api_version', '202505'), 'X-Restli-Protocol-Version' => '2.0.0'])
            ->acceptJson()->timeout(30);
    }

    private function get(AdConnection $c, string $pathAndQuery): array
    {
        $response = $this->client($c)->get(self::BASE.$pathAndQuery);

        if ($response->failed()) {
            throw new RuntimeException('LinkedIn API error '.$response->status().': '.$response->json('message', 'request failed'));
        }

        return $response->json() ?? [];
    }

    /** @return list<array<string, mixed>> */
    private function paged(AdConnection $c, string $pathAndQuery): array
    {
        $rows = [];
        $start = 0;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $data = $this->get($c, $pathAndQuery.(str_contains($pathAndQuery, '?') ? '&' : '?')."start={$start}&count=100");
            $batch = $data['elements'] ?? [];
            array_push($rows, ...$batch);

            if (count($batch) < 100) {
                break;
            }
            $start += 100;
        }

        return $rows;
    }

    public static function idFromUrn(string $urn): string
    {
        $pos = strrpos($urn, ':');

        return $pos === false ? $urn : substr($urn, $pos + 1);
    }

    private function status(?string $status): string
    {
        return match (strtoupper((string) $status)) {
            'ACTIVE' => 'active',
            'PAUSED', 'DRAFT' => 'paused',
            'ARCHIVED', 'CANCELED', 'COMPLETED', 'REMOVED' => 'ended',
            default => 'unknown',
        };
    }
}
