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
 * Meta's Marketing API (Graph v21).
 *
 * Reads are paged and bounded by a page cap. Writes are single POSTs to the
 * object; anything Meta creates here is created PAUSED, so nothing starts
 * spending because a proposal was approved — a person turns it on.
 */
class MetaAdsAdapter extends AbstractAdAdapter
{
    private const BASE = 'https://graph.facebook.com/v21.0';

    private const MAX_PAGES = 20;

    protected const SUPPORTED = [
        'pause', 'resume', 'update_budget', 'duplicate_ad_set', 'create_campaign',
        'create_ad', 'update_creative', 'update_targeting', 'schedule',
    ];

    public function platform(): string
    {
        return AdPlatform::Meta->value;
    }

    public function requiredCredentials(): array
    {
        return ['access_token'];
    }

    public function optionalSettings(): array
    {
        return ['account_ids', 'page_ids'];
    }

    public function accounts(AdConnection $connection): array
    {
        $only = (array) $connection->setting('account_ids', []);

        return collect($this->paged($connection, '/me/adaccounts', ['fields' => 'account_id,name,currency,account_status', 'limit' => 100]))
            ->map(fn (array $row) => [
                'external_id' => (string) ($row['account_id'] ?? ltrim((string) ($row['id'] ?? ''), 'act_')),
                'name' => $row['name'] ?? null,
                'currency' => $row['currency'] ?? null,
                // 1 is ACTIVE in Meta's account_status enum; anything else is not spendable.
                'status' => ((int) ($row['account_status'] ?? 1)) === 1 ? 'active' : 'paused',
            ])
            ->filter(fn (array $row) => $row['external_id'] !== '' && ($only === [] || in_array($row['external_id'], $only, true)))
            ->values()->all();
    }

    public function structure(AdConnection $connection, AdAccount $account): array
    {
        $act = '/act_'.$account->external_id;

        $campaigns = collect($this->paged($connection, $act.'/campaigns', [
            'fields' => 'id,name,effective_status,objective,daily_budget,lifetime_budget,start_time,stop_time', 'limit' => 200,
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

        $adSets = collect($this->paged($connection, $act.'/adsets', [
            'fields' => 'id,name,campaign_id,effective_status,daily_budget,targeting', 'limit' => 200,
        ]))->map(fn (array $row) => [
            'external_id' => (string) $row['id'],
            'campaign_external_id' => (string) ($row['campaign_id'] ?? ''),
            'name' => (string) ($row['name'] ?? $row['id']),
            'status' => $this->status($row['effective_status'] ?? null),
            'daily_budget_minor' => isset($row['daily_budget']) ? (int) $row['daily_budget'] : null,
            'targeting' => $row['targeting'] ?? null,
        ])->all();

        $ads = collect($this->paged($connection, $act.'/ads', [
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

    public function metrics(AdConnection $connection, AdAccount $account, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = $this->paged($connection, '/act_'.$account->external_id.'/insights', [
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
            'leads' => $this->sumActions($row['actions'] ?? [], ['lead', 'onsite_conversion.lead_grouped']),
            'purchases' => $this->sumActions($row['actions'] ?? [], ['purchase', 'omni_purchase']),
            'revenue_minor' => (int) round($this->sumActions($row['action_values'] ?? [], ['purchase', 'omni_purchase'], true) * 100),
            'currency' => $row['account_currency'] ?? $account->currency,
        ])->filter(fn (array $row) => $row['campaign_external_id'] !== '')->values()->all();
    }

    /** Lead forms live on Facebook Pages; listed for the pages the connection names. */
    public function leadForms(AdConnection $connection, AdAccount $account): ?array
    {
        $forms = [];

        foreach ((array) $connection->setting('page_ids', []) as $page) {
            foreach ($this->paged($connection, '/'.$page.'/leadgen_forms', ['fields' => 'id,name,status', 'limit' => 100]) as $row) {
                $forms[] = ['external_id' => (string) $row['id'], 'name' => (string) ($row['name'] ?? $row['id']), 'status' => $row['status'] ?? null];
            }
        }

        return $forms;
    }

    // ------------------------------------------------------------- actions

    protected function pause(AdConnection $c, array $target, array $payload): array
    {
        return $this->post($c, '/'.$this->requireTarget($target, 'campaign', 'ad_set', 'ad'), ['status' => 'PAUSED']);
    }

    protected function resume(AdConnection $c, array $target, array $payload): array
    {
        return $this->post($c, '/'.$this->requireTarget($target, 'campaign', 'ad_set', 'ad'), ['status' => 'ACTIVE']);
    }

    protected function updateBudget(AdConnection $c, array $target, array $payload): array
    {
        // Minor units, which is what Meta's budget fields are in.
        return $this->post($c, '/'.$this->requireTarget($target, 'campaign', 'ad_set'), ['daily_budget' => (int) ($payload['daily_budget_minor'] ?? 0)]);
    }

    protected function duplicateAdSet(AdConnection $c, array $target, array $payload): array
    {
        $id = $this->requireTarget($target, 'ad_set');

        return $this->post($c, "/{$id}/copies", ['status_option' => 'PAUSED', 'deep_copy' => 'true'], 'copied_adset_id');
    }

    protected function createCampaign(AdConnection $c, array $target, array $payload): array
    {
        return $this->post($c, '/act_'.$this->requireAccount($target).'/campaigns', [
            'name' => (string) ($payload['name'] ?? 'Campaign'),
            'objective' => (string) ($payload['objective'] ?? 'OUTCOME_LEADS'),
            'status' => 'PAUSED',
            'special_ad_categories' => json_encode($payload['special_ad_categories'] ?? []),
        ], 'id');
    }

    protected function createAd(AdConnection $c, array $target, array $payload): array
    {
        $adSet = $this->requireTarget($target, 'ad_set');

        if (blank($payload['creative_id'] ?? null)) {
            throw new RuntimeException('A new Meta ad needs an existing creative to use.');
        }

        return $this->post($c, '/act_'.$this->requireAccount($target).'/ads', [
            'name' => (string) ($payload['name'] ?? 'Ad'),
            'adset_id' => $adSet,
            'creative' => json_encode(['creative_id' => (string) $payload['creative_id']]),
            'status' => 'PAUSED',
        ], 'id');
    }

    /** Meta creatives cannot be edited; an ad's creative is replaced with another existing one. */
    protected function updateCreative(AdConnection $c, array $target, array $payload): array
    {
        $ad = $this->requireTarget($target, 'ad');

        if (blank($payload['creative_id'] ?? null)) {
            throw new RuntimeException('Replacing a Meta creative needs the creative to replace it with.');
        }

        return $this->post($c, "/{$ad}", ['creative' => json_encode(['creative_id' => (string) $payload['creative_id']])]);
    }

    protected function updateTargeting(AdConnection $c, array $target, array $payload): array
    {
        $adSet = $this->requireTarget($target, 'ad_set');

        if (! is_array($payload['targeting'] ?? null)) {
            throw new RuntimeException('Targeting must be given as Meta targeting fields.');
        }

        return $this->post($c, "/{$adSet}", ['targeting' => json_encode($payload['targeting'])]);
    }

    protected function schedule(AdConnection $c, array $target, array $payload): array
    {
        $adSet = $this->requireTarget($target, 'ad_set');

        return $this->post($c, "/{$adSet}", array_filter([
            'start_time' => $payload['starts_at'] ?? null,
            'end_time' => $payload['ends_at'] ?? null,
        ]));
    }

    // ------------------------------------------------------------- plumbing

    private function client(AdConnection $c): PendingRequest
    {
        $token = $c->credential('access_token');

        if (! $token) {
            throw new RuntimeException('Meta access token is missing.');
        }

        return Http::withToken((string) $token)->timeout(30);
    }

    private function post(AdConnection $c, string $path, array $fields, ?string $createdKey = null): array
    {
        $response = $this->client($c)->asForm()->post(self::BASE.$path, $fields);
        $ok = $response->successful() && $response->json('success', true) !== false;

        return $this->answer($response, ['path' => $path, 'fields' => $fields], $createdKey ? (string) $response->json($createdKey) : null, $ok ? null : $this->errorMessage($response));
    }

    /** @return list<array<string, mixed>> */
    private function paged(AdConnection $c, string $path, array $query): array
    {
        $rows = [];
        $url = self::BASE.$path;
        $params = $query;

        for ($page = 0; $page < self::MAX_PAGES && $url; $page++) {
            $response = $this->client($c)->get($url, $params);

            if ($response->failed()) {
                // The platform's own message, so "token expired" reads as that. Never the token.
                throw new RuntimeException('Meta Graph API error '.$response->status().': '.$response->json('error.message', 'request failed'));
            }

            array_push($rows, ...($response->json('data') ?? []));
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

    private function sumActions(array $actions, array $types, bool $float = false): int|float
    {
        $sum = (float) collect($actions)->whereIn('action_type', $types)->sum(fn ($a) => (float) ($a['value'] ?? 0));

        return $float ? $sum : (int) $sum;
    }
}
