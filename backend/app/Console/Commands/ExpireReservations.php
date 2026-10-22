<?php

namespace App\Console\Commands;

use App\Domain\Visits\ReservationService;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;

class ExpireReservations extends Command
{
    protected $signature = 'leadflow:expire-reservations';

    protected $description = 'Release the units held by reservations nobody confirmed in time';

    public function handle(ReservationService $reservations, TenantContext $context): int
    {
        $total = 0;

        $context->withoutScope(fn () => Tenant::where('is_active', true)->get())->each(function (Tenant $tenant) use ($reservations, $context, &$total) {
            try {
                $context->set($tenant);
                $total += $reservations->expireDue();
            } catch (\Throwable $e) {
                $this->error("Tenant {$tenant->slug}: {$e->getMessage()}");
                report($e);
            }
        });

        $this->info("Reservations: {$total} expired.");

        return self::SUCCESS;
    }
}
