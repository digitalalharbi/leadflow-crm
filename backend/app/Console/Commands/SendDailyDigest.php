<?php

namespace App\Console\Commands;

use App\Domain\Digest\DailyDigest;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\DailyDigestNotification;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * The one thing in مهتم that goes looking for a person.
 *
 * Everything else waits to be opened. A form that stopped posting, eleven
 * leads nobody called, a follow-up two days overdue — each is visible on a
 * screen and invisible until somebody goes to that screen, and the cost is
 * paid quietly in the meantime.
 */
class SendDailyDigest extends Command
{
    protected $signature = 'leadflow:daily-digest {--tenant= : only this tenant slug}';

    protected $description = 'Send each workspace its managers a summary of yesterday and what is waiting';

    public function handle(DailyDigest $digest, TenantContext $context): int
    {
        $sent = 0;
        $skipped = 0;

        $tenants = $context->withoutScope(fn () => Tenant::with('settings')->where('is_active', true)
            ->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug))
            ->get());

        foreach ($tenants as $tenant) {
            try {
                $context->withTenant($tenant, function () use ($tenant, $digest, &$sent, &$skipped) {
                    $figures = $digest->for($tenant);

                    if (! $digest->worthSending($figures)) {
                        // Logged rather than silent: a quiet workspace and a
                        // broken scheduler look identical from the outside, and
                        // this is the line that tells them apart.
                        $this->line("{$tenant->slug}: nothing to report");
                        $skipped++;

                        return;
                    }

                    /*
                     * Admins and managers only. A rep's job is their own list,
                     * and these figures span the workspace — the same authority
                     * the reports and data-quality screens require.
                     */
                    $recipients = User::where('tenant_id', $tenant->id)
                        ->where('is_active', true)
                        ->whereIn('role', [Role::Admin->value, Role::Manager->value])
                        ->get();

                    foreach ($recipients as $user) {
                        $user->notify(new DailyDigestNotification($figures));
                        $sent++;
                    }
                });
            } catch (Throwable $e) {
                // One workspace's bad data must not cost everybody else their
                // morning summary.
                $this->error("Tenant {$tenant->slug}: {$e->getMessage()}");
                report($e);
            }
        }

        $this->info("Daily digest: {$sent} notification(s) sent, {$skipped} workspace(s) with nothing to report.");

        return self::SUCCESS;
    }
}
