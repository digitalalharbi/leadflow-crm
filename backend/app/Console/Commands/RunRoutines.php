<?php

namespace App\Console\Commands;

use App\Domain\Agents\AgentDirectory;
use App\Domain\Agents\Orchestrator;
use App\Models\Routine;
use App\Models\Tenant;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Console\Command;
use Throwable;

/**
 * Every few minutes: run whichever routines are due, in each workspace's own
 * timezone ("8:45 every morning" means the client's morning).
 */
class RunRoutines extends Command
{
    protected $signature = 'leadflow:run-routines {--tenant= : only this tenant slug} {--force= : run this routine now regardless of schedule}';

    protected $description = 'Run the agent routines that are due';

    public function handle(Orchestrator $orchestrator, AgentDirectory $directory, TenantContext $context): int
    {
        $tenants = $context->withoutScope(fn () => Tenant::where('is_active', true)
            ->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug))
            // Read below for every tenant; where lazy loading is forbidden (everywhere but production) a lazy read failed the tenant.
            ->with('settings')
            ->get());

        $ran = 0;

        foreach ($tenants as $tenant) {
            try {
                $context->withTenant($tenant, function () use ($orchestrator, $directory, $tenant, &$ran) {
                    $directory->ensure();
                    $zone = $tenant->settings?->timezone ?: 'Asia/Riyadh';
                    $now = CarbonImmutable::now($zone);

                    foreach (Routine::query()->where('is_active', true)->get() as $routine) {
                        if ($this->option('force') ? $this->option('force') !== $routine->key : ! $this->due($routine, $now)) {
                            continue;
                        }

                        $orchestrator->run($routine->key);
                        $ran++;
                    }
                });
            } catch (Throwable $e) {
                $this->error("Tenant {$tenant->slug}: {$e->getMessage()}");
                report($e);
            }
        }

        $this->info("Ran {$ran} routine(s).");

        return self::SUCCESS;
    }

    /** Due if its last scheduled moment is after the last run — so a missed tick runs once, not never. */
    private function due(Routine $routine, CarbonImmutable $now): bool
    {
        $previous = CarbonImmutable::instance((new CronExpression($routine->cron))->getPreviousRunDate($now->toDateTime(), 0, true, $now->timezoneName));

        return $routine->last_run_at === null
            ? $previous->greaterThan($now->subMinutes(15))
            : $previous->greaterThan(CarbonImmutable::instance($routine->last_run_at));
    }
}
