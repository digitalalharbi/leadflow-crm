<?php

namespace App\Domain\Campaigns;

use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\Campaign;
use App\Models\Creative;
use App\Models\Integration;
use App\Models\MetricSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reads an ad platform into the campaign tables.
 *
 * Idempotent by construction: every entity is matched on the platform's own id
 * and every snapshot on (entity, day), so a second run — the hourly schedule, a
 * retry after a timeout, a manual "sync now" — corrects what is there and
 * duplicates nothing.
 *
 * Failure is reported and not hidden: `last_synced_at` is only moved when data
 * actually arrived, so an account whose token expired last week reads as last
 * synced last week, not a minute ago.
 */
class CampaignSyncer
{
    public function __construct(private AdPlatformRegistry $registry, private CampaignResolver $resolver) {}

    /**
     * @return array{accounts: int, campaigns: int, ads: int, snapshots: int, error: ?string}
     */
    public function sync(Integration $integration, int $days = 7): array
    {
        $result = ['accounts' => 0, 'campaigns' => 0, 'ads' => 0, 'snapshots' => 0, 'error' => null];

        $platform = $integration->provider?->value;
        $adapter = $platform ? $this->registry->get($platform) : null;

        if ($adapter === null || ! $adapter->isImplemented() || ! $adapter->isConfigured($integration)) {
            return $result;
        }

        try {
            $accounts = $adapter->accounts($integration);
        } catch (Throwable $e) {
            return $this->failed($integration, $result, $e);
        }

        foreach ($accounts as $row) {
            $account = AdAccount::query()->firstOrNew(['platform' => $platform, 'external_id' => $row['external_id']]);
            $account->fill([
                'integration_id' => $integration->id,
                'name' => $row['name'] ?? $account->name,
                'currency' => $row['currency'] ?? $account->currency,
                'status' => $row['status'] ?? 'active',
            ])->save();

            try {
                $counts = $this->syncAccount($adapter, $integration, $account, $days);
                $result['campaigns'] += $counts['campaigns'];
                $result['ads'] += $counts['ads'];
                $result['snapshots'] += $counts['snapshots'];
                $result['accounts']++;
                $account->forceFill(['last_synced_at' => now(), 'last_error' => null])->save();
            } catch (Throwable $e) {
                // One account failing must not stop its siblings.
                $account->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 500)])->save();
                $result['error'] = $e->getMessage();
            }
        }

        return $result;
    }

    private function syncAccount($adapter, Integration $integration, AdAccount $account, int $days): array
    {
        $structure = $adapter->structure($integration, $account);
        $platform = $account->platform;

        $campaigns = [];
        foreach ($structure['campaigns'] as $row) {
            $campaign = $this->resolver->campaign($platform, $row['external_id'], $row['name'], $account->project_id);
            $campaign->fill([
                'ad_account_id' => $account->id,
                'origin' => Campaign::ORIGIN_SYNC,
                'name' => $row['name'],
                'status' => $row['status'] ?? 'unknown',
                'objective' => $row['objective'] ?? null,
                'daily_budget_minor' => $row['daily_budget_minor'] ?? null,
                'lifetime_budget_minor' => $row['lifetime_budget_minor'] ?? null,
                'currency' => $account->currency,
                'starts_at' => $row['starts_at'] ?? null,
                'ends_at' => $row['ends_at'] ?? null,
                'last_synced_at' => now(),
                'archived_at' => null,
            ])->save();
            $campaigns[$row['external_id']] = $campaign;
        }

        // Gone from the platform: archived, never deleted. Its leads and its
        // history are still true.
        Campaign::query()
            ->where('ad_account_id', $account->id)
            ->where('origin', Campaign::ORIGIN_SYNC)
            ->whereNull('archived_at')
            ->whereNotIn('external_id', array_keys($campaigns) ?: ['-'])
            ->update(['archived_at' => now()]);

        $adSets = [];
        foreach ($structure['ad_sets'] as $row) {
            $campaign = $campaigns[$row['campaign_external_id']] ?? null;
            if ($campaign === null) {
                continue;
            }

            $adSet = $this->resolver->adSet($campaign, $row['external_id'], $row['name']);
            $adSet->update([
                'name' => $row['name'],
                'status' => $row['status'] ?? 'unknown',
                'daily_budget_minor' => $row['daily_budget_minor'] ?? null,
                'archived_at' => null,
            ]);
            $adSets[$row['external_id']] = $adSet;
        }

        $ads = [];
        foreach ($structure['ads'] as $row) {
            $campaign = $campaigns[$row['campaign_external_id']] ?? null;
            if ($campaign === null) {
                continue;
            }

            $creative = null;
            if (! empty($row['creative'])) {
                $c = $row['creative'];
                $creative = Creative::query()->firstOrNew(['platform' => $platform, 'external_id' => $c['external_id'] ?? null]);
                $creative->fill(array_intersect_key($c, array_flip(['name', 'format', 'headline', 'body', 'call_to_action', 'media_url', 'thumbnail_url'])))->save();
            }

            $adSet = $adSets[$row['ad_set_external_id'] ?? ''] ?? null;
            $ad = $this->resolver->ad($campaign, $adSet, $row['external_id'], $row['name']);
            $ad->update([
                'ad_set_id' => $adSet?->id,
                'creative_id' => $creative?->id,
                'name' => $row['name'],
                'status' => $row['status'] ?? 'unknown',
                'archived_at' => null,
            ]);
            $ads[$row['external_id']] = $ad;
        }

        $snapshots = 0;
        $to = CarbonImmutable::today();
        $rows = $adapter->metrics($integration, $account, $to->subDays(max(1, $days) - 1), $to);

        DB::transaction(function () use ($rows, $campaigns, $adSets, $ads, $account, $platform, &$snapshots) {
            foreach ($rows as $row) {
                $campaign = $campaigns[$row['campaign_external_id']] ?? null;
                if ($campaign === null) {
                    continue;
                }

                $adSet = $adSets[$row['ad_set_external_id'] ?? ''] ?? null;
                $ad = $ads[$row['ad_external_id'] ?? ''] ?? null;

                MetricSnapshot::query()->updateOrCreate(
                    [
                        'level' => $row['level'],
                        'campaign_id' => $campaign->id,
                        'ad_set_id' => $adSet?->id,
                        'ad_id' => $ad?->id,
                        'date' => $row['date'],
                    ],
                    [
                        'project_id' => $campaign->project_id,
                        'ad_account_id' => $account->id,
                        'platform' => $platform,
                        'spend_minor' => $row['spend_minor'],
                        'impressions' => $row['impressions'],
                        'clicks' => $row['clicks'],
                        'leads' => $row['leads'],
                        'purchases' => $row['purchases'],
                        'revenue_minor' => $row['revenue_minor'],
                        'currency' => $row['currency'] ?? $account->currency,
                    ],
                );
                $snapshots++;
            }
        });

        return ['campaigns' => count($campaigns), 'ads' => count($ads), 'snapshots' => $snapshots];
    }

    private function failed(Integration $integration, array $result, Throwable $e): array
    {
        $integration->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 500)])->save();
        $result['error'] = $e->getMessage();

        return $result;
    }
}
