<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Models\Tenant;
use App\Services\Integrations\LeadPuller;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ask every configured pull-based source what it has.
 *
 * The missing half of the ingestion story. Push providers deliver because the
 * source calls our webhook; pull providers deliver because somebody calls
 * theirs, and nobody did — `fetch()` was implemented five times and invoked
 * nowhere. A tenant with a valid TikTok token had a green badge and no leads.
 */
class PullProviderLeads extends Command
{
    protected $signature = 'leadflow:pull-leads
        {--tenant= : only this tenant slug}
        {--integration= : only this integration uuid}';

    protected $description = 'Fetch leads from every configured pull-based integration';

    public function handle(LeadPuller $puller, TenantContext $context): int
    {
        $totals = ['fetched' => 0, 'created' => 0, 'duplicates' => 0, 'skipped' => 0, 'rejected' => 0, 'failed' => 0];
        $broken = 0;

        $tenants = $context->withoutScope(fn () => Tenant::where('is_active', true)
            ->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug))
            ->get());

        foreach ($tenants as $tenant) {
            // Each tenant inside its own scope: one workspace's broken source
            // must not stop the poll for everybody else.
            try {
                $integrations = $context->withoutScope(fn () => Integration::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('is_active', true)
                    ->when($this->option('integration'), fn ($q, $uuid) => $q->where('uuid', $uuid))
                    ->get());

                foreach ($integrations as $integration) {
                    $result = $puller->pull($tenant, $integration);

                    foreach (array_keys($totals) as $key) {
                        $totals[$key] += $result[$key];
                    }

                    if ($result['error'] !== null) {
                        $broken++;
                        $this->warn("{$tenant->slug}/{$integration->provider?->value}: {$result['error']}");
                    }
                }
            } catch (Throwable $e) {
                $broken++;
                $this->error("Tenant {$tenant->slug}: {$e->getMessage()}");
                report($e);
            }
        }

        $this->info(sprintf(
            'Pulled %d row(s): %d new, %d re-engaged, %d already taken, %d rejected, %d failed.',
            $totals['fetched'], $totals['created'], $totals['duplicates'],
            $totals['skipped'], $totals['rejected'], $totals['failed'],
        ));

        /*
         * A source that could not be reached is a non-zero exit, so a cron that
         * only ever looks at the status still notices. The leads that did come
         * through are kept either way — a partial poll is better than none.
         */
        return $broken > 0 ? self::FAILURE : self::SUCCESS;
    }
}
