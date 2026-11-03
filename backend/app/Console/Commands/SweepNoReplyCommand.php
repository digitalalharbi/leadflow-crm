<?php

namespace App\Console\Commands;

use App\Domain\Automation\NoReplySweeper;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;

class SweepNoReplyCommand extends Command
{
    protected $signature = 'leadflow:sweep-no-reply';

    protected $description = 'Fire "no reply" for leads who have gone quiet since we last wrote.';

    public function handle(NoReplySweeper $sweeper, TenantContext $context): int
    {
        $total = 0;

        Tenant::query()->where('is_active', true)->each(function (Tenant $tenant) use ($sweeper, $context, &$total) {
            // One company's bad data must not cost the others their sweep.
            try {
                $total += $context->withTenant($tenant, fn () => $sweeper->sweep($tenant));
            } catch (\Throwable $e) {
                $this->error("Tenant {$tenant->slug}: {$e->getMessage()}");
                report($e);
            }
        });

        $this->info("{$total} no-reply moment(s) fired.");

        return self::SUCCESS;
    }
}
