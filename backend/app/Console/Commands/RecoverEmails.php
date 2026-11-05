<?php

namespace App\Console\Commands;

use App\Domain\Mail\EmailDeliveries;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * An email left marked as sending by a worker that stopped is found and put back in line. Each company in its own
 * scope, and one company's bad row does not cost the others their sweep.
 */
class RecoverEmails extends Command
{
    protected $signature = 'leadflow:recover-emails {--minutes=15 : How long a send may stay unfinished before it is counted as stopped}';

    protected $description = 'Put back in line the emails whose send was interrupted';

    public function handle(TenantContext $context, EmailDeliveries $deliveries): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $total = 0;

        $context->withoutScope(fn () => Tenant::where('is_active', true)->get())->each(function (Tenant $tenant) use ($context, $deliveries, $minutes, &$total) {
            try {
                $total += (int) $context->withTenant($tenant, fn () => $deliveries->recoverStalled($minutes));
            } catch (\Throwable $e) {
                $this->error("Tenant {$tenant->slug}: {$e->getMessage()}");
                report($e);
            }
        });

        $this->info("recovered={$total}");

        return self::SUCCESS;
    }
}
