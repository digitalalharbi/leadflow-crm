<?php

namespace App\Console\Commands;

use App\Domain\Campaigns\CampaignResolver;
use App\Domain\Campaigns\CampaignSyncer;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Hourly: read every connected ad account into the campaign tables, then link
 * any lead that has not yet been tied to its campaign.
 */
class SyncCampaigns extends Command
{
    protected $signature = 'leadflow:sync-campaigns
        {--tenant= : only this tenant slug}
        {--days=7 : how many days of metrics to re-read}';

    protected $description = 'Sync ad accounts, campaigns and metrics, and attribute leads to campaigns';

    public function handle(CampaignSyncer $syncer, CampaignResolver $resolver, TenantContext $context): int
    {
        $broken = 0;
        $linked = 0;

        $tenants = $context->withoutScope(fn () => Tenant::where('is_active', true)
            ->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug))
            ->get());

        foreach ($tenants as $tenant) {
            try {
                $context->withTenant($tenant, function () use ($syncer, $resolver, &$broken, &$linked, $tenant) {
                    foreach (Integration::query()->where('is_active', true)->get() as $integration) {
                        $result = $syncer->sync($integration, (int) $this->option('days'));

                        if ($result['error'] !== null) {
                            $broken++;
                            $this->warn("{$tenant->slug}/{$integration->provider?->value}: {$result['error']}");
                        }
                    }

                    // Attribution needs no ad account: every lead that carries a
                    // campaign gets its row.
                    Lead::query()->whereNull('campaign_ref_id')
                        ->where(fn ($q) => $q->whereNotNull('campaign_name')->orWhereNotNull('campaign_id'))
                        ->chunkById(200, function ($leads) use ($resolver, &$linked) {
                            foreach ($leads as $lead) {
                                $linked += $resolver->linkLead($lead) ? 1 : 0;
                            }
                        });
                });
            } catch (Throwable $e) {
                $broken++;
                $this->error("Tenant {$tenant->slug}: {$e->getMessage()}");
                report($e);
            }
        }

        $this->info("Attributed {$linked} lead(s) to campaigns.");

        return $broken > 0 ? self::FAILURE : self::SUCCESS;
    }
}
