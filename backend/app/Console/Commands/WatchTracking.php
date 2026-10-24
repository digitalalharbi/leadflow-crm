<?php

namespace App\Console\Commands;

use App\Domain\Notifications\ImportantAlerts;
use App\Domain\Tracking\TrackingHealth;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/** Tells the managers when a platform they connected stops delivering or stops being told about sales. */
class WatchTracking extends Command
{
    protected $signature = 'leadflow:watch-tracking';

    protected $description = 'Alert the managers when a connected platform is at risk';

    public function handle(TrackingHealth $health, TenantContext $context): int
    {
        $alerts = 0;

        $context->withoutScope(fn () => Tenant::where('is_active', true)->get())->each(function (Tenant $tenant) use ($health, $context, &$alerts) {
            try {
                $context->withTenant($tenant, function () use ($health, &$alerts) {
                    foreach ($health->build(7)['platforms'] as $row) {
                        // Only what somebody connected: an idle platform nobody set up is not a failure.
                        if ($row['connected'] && $row['status'] === 'risk') {
                            ImportantAlerts::trackingFailure($row['platform'], $row['platform'], $this->why($row));
                            $alerts++;
                        }
                    }
                });
            } catch (\Throwable $e) {
                $this->error("Tenant {$tenant->slug}: {$e->getMessage()}");
                report($e);
            }
        });

        $this->info("Tracking watch: {$alerts} platform(s) at risk.");

        return self::SUCCESS;
    }

    /** One sentence with the number in it. @param  array<string,mixed>  $row */
    private function why(array $row): string
    {
        return match (true) {
            ! $row['receiving'] => 'لم يصل أي حدث منذ يومين — تحقق من الربط.',
            ($row['success_rate'] ?? 100) < 80 => "نسبة نجاح الاستقبال {$row['success_rate']}% فقط.",
            default => 'صفقات مُغلقة لم تُرسل إلى المنصة منذ ٤٨ ساعة.',
        };
    }
}
