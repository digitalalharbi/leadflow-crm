<?php

namespace App\Console\Commands;

use App\Domain\Sla\SlaSweeper;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;

class SweepSlaBreaches extends Command
{
    protected $signature = 'leadflow:sweep-sla';

    protected $description = 'Remind, escalate and reassign leads nobody has contacted in time';

    public function handle(SlaSweeper $sweeper, TenantContext $context): int
    {
        $totals = ['reminded' => 0, 'escalated' => 0, 'reassigned' => 0];

        // Every active tenant, one at a time, each inside its own scope. One
        // tenant's bad data must not stop the sweep for the rest.
        // `settings` is loaded here: the sweeper reads it for every tenant, and where lazy loading
        // is forbidden (every environment but production) a lazy read failed each tenant's sweep.
        $context->withoutScope(fn () => Tenant::where('is_active', true)->with('settings')->get())
            ->each(function (Tenant $tenant) use ($sweeper, &$totals) {
                try {
                    $counts = $sweeper->sweep($tenant);

                    foreach ($counts as $key => $value) {
                        $totals[$key] += $value;
                    }
                } catch (\Throwable $e) {
                    $this->error("Tenant {$tenant->slug}: {$e->getMessage()}");
                    report($e);
                }
            });

        $this->info(sprintf(
            'SLA sweep: %d reminded, %d escalated, %d reassigned.',
            $totals['reminded'], $totals['escalated'], $totals['reassigned'],
        ));

        return self::SUCCESS;
    }
}
