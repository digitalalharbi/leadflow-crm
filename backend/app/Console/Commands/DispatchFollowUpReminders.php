<?php

namespace App\Console\Commands;

use App\Models\FollowUp;
use App\Models\Tenant;
use App\Notifications\FollowUpDueNotification;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Scheduled hourly. Notifies reps about follow-ups due today and ones that have
 * slipped, which is what keeps the "overdue" number on the dashboard honest.
 */
class DispatchFollowUpReminders extends Command
{
    protected $signature = 'leadflow:follow-up-reminders';

    protected $description = 'Notify sales reps about due and overdue follow-ups';

    public function handle(TenantContext $context): int
    {
        $tenants = $context->withoutScope(fn () => Tenant::where('is_active', true)->get());
        $sent = 0;

        foreach ($tenants as $tenant) {
            $context->withTenant($tenant, function () use (&$sent) {
                // Each is announced once when it falls due and once if it slips —
                // the job runs hourly, and a reminder that repeats hourly is muted.
                $overdue = FollowUp::query()->overdue()->whereNull('overdue_reminded_at')->with('lead', 'assignee')->get();
                $due = FollowUp::query()->dueToday()->whereNull('reminded_at')->whereNotIn('id', $overdue->pluck('id'))->with('lead', 'assignee')->get();

                foreach ($due as $followUp) {
                    $followUp->assignee?->notify(new FollowUpDueNotification($followUp, overdue: false));
                    $followUp->forceFill(['reminded_at' => now()])->saveQuietly();
                    $sent++;
                }

                foreach ($overdue as $followUp) {
                    $followUp->assignee?->notify(new FollowUpDueNotification($followUp, overdue: true));
                    $followUp->forceFill(['reminded_at' => $followUp->reminded_at ?? now(), 'overdue_reminded_at' => now()])->saveQuietly();
                    $sent++;
                }
            });
        }

        $this->info("Dispatched {$sent} follow-up reminders.");

        return self::SUCCESS;
    }
}
