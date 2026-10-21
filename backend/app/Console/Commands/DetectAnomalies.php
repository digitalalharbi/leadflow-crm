<?php

namespace App\Console\Commands;

use App\Domain\Insights\AnomalyDetector;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/** Looks for numbers that moved outside their normal range, per workspace, and tells the managers once. */
class DetectAnomalies extends Command
{
    protected $signature = 'leadflow:detect-anomalies {--tenant= : only this tenant slug}';

    protected $description = 'Find numbers outside their normal range and alert the managers';

    public function handle(AnomalyDetector $detector, TenantContext $context): int
    {
        $total = 0;

        $context->withoutScope(fn () => Tenant::where('is_active', true)->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug))->get())
            ->each(function (Tenant $tenant) use ($detector, $context, &$total) {
                try {
                    $total += count($context->withTenant($tenant, fn () => $detector->run()));
                } catch (\Throwable $e) {
                    $this->error("Tenant {$tenant->slug}: {$e->getMessage()}");
                    report($e);
                }
            });

        $this->info("Anomalies: {$total} found.");

        return self::SUCCESS;
    }
}
