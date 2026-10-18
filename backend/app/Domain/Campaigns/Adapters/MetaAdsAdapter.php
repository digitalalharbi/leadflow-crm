<?php

namespace App\Domain\Campaigns\Adapters;

use App\Domain\Campaigns\AdPlatform;
use App\Domain\Campaigns\Contracts\AdPlatformAdapter;
use App\Models\AdAccount;
use App\Models\Integration;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Meta's Marketing API, read side.
 *
 * Graph API calls are paged and bounded: a workspace with a very large account
 * is read in full up to a page cap, and the cap is a number rather than a loop
 * that runs until the platform stops answering.
 */
class MetaAdsAdapter implements AdPlatformAdapter
{
    private const BASE = 'https://graph.facebook.com/v21.0';

    private const MAX_PAGES = 20;

    public function platform(): string
    {
        return AdPlatform::Meta->value;
    }

    public function isImplemented(): bool
    {
        return true;
    }

    public function isConfigured(?Integration $integration): bool
    {
        return $integration !== null && ! empty(data_get($integration->credentials, 'access_token'));
    }

    public function requiredCredentials(): array
    {
        return ['access_token'];
    }

    public function actions(): array
    {
        return ['pause', 'resume', 'update_budget'];
    }

    public function accounts(Integration $integration): array
    {
        return collect($this->paged($integration, '/me/adaccounts', [
            'fields' => 'account_id,name,currency,account_status',
            'limit' => 100,
        ]))->map(fn (array $row) => [
            'external_id' => (string) ($row['account_id'] ?? ltrim((string) ($row['id'] ?? ''), 'act_')),
            'name' => $row['name'] ?? null,
            'currency' => $row['currency'] ?? null,
            // 1 is ACTIVE in Meta's account_status enum; anything else is not spendable.
            'status' => ((int) ($row['account_status'] ?? 1)) === 1 ? 'active' : 'paused',
        ])->filter(fn (array $row) => $row['external_id'] !== '')->values()->all();
    }

    public function structure(Integration $integration, AdAccount $account): array
    {
        $act = '/act_'.$account->external_id;

        $campaigns = collect($this->paged($integration, $act.'/campaigns', [
            'fields' => 'id,name,effective_status,objective,daily_budget,lifetime_budget,start_time,stop_time',
            'limit' => 200,
        ]))->map(fn (array $row) => [
            'external_id' => (string) $row['id'],
            'name' => (string) ($row['name'] ?? $row['id']),
            'status' => $this->status($row['effective_status'] ?? null),
            'objective' => $row['objective'] ?? null,
            'daily_budget_minor' => isset($row['daily_budget']) ? (int) $row['daily_budget'] : null,
            'lifetime_budget_minor' => isset($row['lifetime_budget']) ? (int) $row['lifetime_budget'] : null,
            'starts_at' => $row['start_time'] ?? null,
            'ends_at' => $row['stop_time'] ?? null,
        ])->all();

        $adSets = collect($this->paged($integration, $act.'/adsets', [
            'fields' => 'id,name,campaign_id,effective_status,daily_budget',
            'limit' => 200,
        ]))->map(fn (array $row) => [
            'external_id' => (string) $row['id'],
            'campaign_external_id' => (string) ($row['campaign_id'] ?? ''),
            'name' => (string) ($row['name'] ?? $row['id']),
            'status' => $this->status($row['effective_status'] ?? null),
            'daily_budget_minor' => isset($row['daily_budget']) ? (int) $row['daily_budget'] : null,
        ])->all();

        $ads = collect($this->paged($integration, $act.'/ads', [
            'fields' => 'id,name,campaign_id,adset_id,effective_status,creative{id,name,title,body,call_to_action_type,thumbnail_url,image_url,object_type}',
            'limit' => 200,
        ]))->map(function (array $row) {
            $creative = $row['creative'] ?? null;

            return [
                'external_id' => (string) $row['id'],
                'campaign_external_id' => (string) ($row['campaign_id'] ?? ''),
                'ad_set_external_id' => isset($row['adset_id']) ? (string) $row['adset_id'] : null,
                'name' => (string) ($row['name'] ?? $row['id']),
                'status' => $this->status($row['effective_status'] ?? null),
                'creative' => $creative ? [
                    'external_id' => isset($creative['id']) ? (string) $creative['id'] : null,
                    'name' => $creative['name'] ?? null,
                    'format' => $this->format($creative['object_type'] ?? null),
                    'headline' => $creative['title'] ?? null,
                    'body' => $creative['body'] ?? null,
                    'call_to_action' => $creative['call_to_action_type'] ?? null,
                    'media_url' => $creative['image_url'] ?? null,
                    'thumbnail_url' => $creative['thumbnail_url'] ?? null,
                ] : null,
            ];
        })->all();

        return ['campaigns' => $campaigns, 'ad_sets' => $adSets, 'ads' => $ads];
    }

    public function metrics(Integration $integration, AdAccount $account, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = $this->paged($integration, '/act_'.$account->external_id.'/insights', [
            'level' => 'ad',
            'time_increment' => 1,
            'fields' => 'campaign_id,adset_id,ad_id,spend,impressions,clicks,actions,action_values,account_currency',
            'time_range' => json_encode(['since' => $from->toDateString(), 'until' => $to->toDateString()]),
            'limit' => 500,
        ]);

        return collect($rows)->map(fn (array $row) => [
            'level' => 'ad',
            'campaign_external_id' => (string) ($row['campaign_id'] ?? ''),
            'ad_set_external_id' => isset($row['adset_id']) ? (string) $row['adset_id'] : null,
            'ad_external_id' => isset($row['ad_id']) ? (string) $row['ad_id'] : null,
            'date' => (string) ($row['date_start'] ?? $from->toDateString()),
            // Meta reports money as a decimal string in the account's currency.
            'spend_minor' => (int) round(((float) ($row['spend'] ?? 0)) * 100),
            'impressions' => (int) ($row['impressions'] ?? 0),
            'clicks' => (int) ($row['clicks'] ?? 0),
            'leads' => $this->action($row['actions'] ?? [], ['lead', 'onsite_conversion.lead_grouped']),
            'purchases' => $this->action($row['actions'] ?? [], ['purchase', 'omni_purchase']),
            'revenue_minor' => (int) round($this->actionValue($row['action_values'] ?? [], ['purchase', 'omni_purchase']) * 100),
            'currency' => $row['account_currency'] ?? $account->currency,
        ])->filter(fn (array $row) => $row['campaign_external_id'] !== '')->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function paged(Integration $integration, string $path, array $query): array
    {
        $token = data_get($integration->credentials, 'access_token');

        if (! $token) {
            throw new RuntimeException('Meta access token is missing.');
        }

        $rows = [];
        $url = self::BASE.$path;
        $params = $query;

        for ($page = 0; $page < self::MAX_PAGES && $url; $page++) {
            $response = Http::withToken($token)->timeout(30)->get($url, $params);

            if ($response->failed()) {
                // The platform's own message, so "token expired" reads as that
                // and not as an anonymous 400. Never the token itself.
                throw new RuntimeException('Meta Graph API error '.$response->status().': '.$response->json('error.message', 'request failed'));
            }

            array_push($rows, ...($response->json('data') ?? []));

            // `paging.next` is a complete URL that already carries the cursor.
            $url = $response->json('paging.next');
            $params = [];
        }

        return $rows;
    }

    private function status(?string $effective): string
    {
        return match (strtoupper((string) $effective)) {
            'ACTIVE' => 'active',
            'PAUSED', 'CAMPAIGN_PAUSED', 'ADSET_PAUSED' => 'paused',
            'ARCHIVED', 'DELETED' => 'ended',
            default => 'unknown',
        };
    }

    private function format(?string $objectType): ?string
    {
        return match (strtoupper((string) $objectType)) {
            'VIDEO' => 'video',
            'PHOTO', 'SHARE', 'STATUS' => 'image',
            'MULTI_SHARE' => 'carousel',
            default => null,
        };
    }

    private function action(array $actions, array $types): int
    {
        return (int) collect($actions)->whereIn('action_type', $types)->sum(fn ($a) => (float) ($a['value'] ?? 0));
    }

    private function actionValue(array $actions, array $types): float
    {
        return (float) collect($actions)->whereIn('action_type', $types)->sum(fn ($a) => (float) ($a['value'] ?? 0));
    }
}
