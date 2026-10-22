<?php

namespace App\Console\Commands;

use App\Domain\Reports\ReportSchedules;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/** Sends the reports that are due: every workspace's schedules whose hour has come. Run hourly; a run that finds nothing due does nothing. */
class SendReportSchedules extends Command
{
    protected $signature = 'leadflow:send-report-schedules {--tenant= : only this tenant slug}';

    protected $description = 'Email the reports whose daily, weekly or monthly delivery is due';

    public function handle(ReportSchedules $schedules, TenantContext $context): int
    {
        $ran = 0;

        $tenants = $context->withoutScope(fn () => Tenant::where('is_active', true)->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug))->get());

        foreach ($tenants as $tenant) {
            try {
                $ran += $context->withTenant($tenant, fn () => $schedules->runDue());
            } catch (\Throwable $e) {
                // One workspace's bad schedule must not cost everybody else their report.
                $this->error("Tenant {$tenant->slug}: {$e->getMessage()}");
                report($e);
            }
        }

        $this->info("Report schedules: {$ran} run.");

        return self::SUCCESS;
    }
}
