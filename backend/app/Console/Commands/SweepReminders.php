<?php

namespace App\Console\Commands;

use App\Domain\Notifications\AlertGate;
use App\Domain\Notifications\ImportantAlerts;
use App\Events\LeadSignal;
use App\Models\Appointment;
use App\Models\Reservation;
use App\Models\Task;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * What comes due with nobody pressing anything: appointments tomorrow and in two hours, tasks due and late, holds about
 * to lapse. Each thing is told once, and one company's bad data does not cost the others their sweep.
 */
class SweepReminders extends Command
{
    protected $signature = 'leadflow:sweep-reminders';

    protected $description = 'Remind people of appointments, tasks and reservations coming due';

    public function handle(TenantContext $context): int
    {
        $context->withoutScope(fn () => Tenant::where('is_active', true)->get())->each(function (Tenant $tenant) use ($context) {
            try {
                $context->withTenant($tenant, fn () => $this->sweep($tenant));
            } catch (\Throwable $e) {
                $this->error("Tenant {$tenant->slug}: {$e->getMessage()}");
                report($e);
            }
        });

        return self::SUCCESS;
    }

    private function sweep(Tenant $tenant): void
    {
        $zone = (string) ($tenant->settings?->timezone ?: 'Asia/Riyadh');

        foreach ([24, 2] as $hours) {
            Appointment::query()->with('lead')
                ->whereIn('status', ['scheduled', Appointment::CONFIRMED, Appointment::RESCHEDULED])
                ->whereBetween('scheduled_at', [now(), now()->addHours($hours)])
                ->each(function (Appointment $appointment) use ($hours, $zone) {
                    $lead = $appointment->lead;

                    if ($lead && AlertGate::once("appointment:{$appointment->id}:{$hours}:{$appointment->scheduled_at->timestamp}")) {
                        $at = $appointment->scheduled_at->setTimezone($zone);
                        ImportantAlerts::aboutLead('appointment_reminder', $lead, ['appointment_date' => $at->format('Y-m-d'), 'appointment_time' => $at->format('H:i')]);
                    }
                });
        }

        Task::query()->where('status', 'open')->whereNotNull('due_at')->whereNotNull('assigned_to')
            ->where('due_at', '<=', now()->addHour())
            ->with('lead')
            ->each(function (Task $task) {
                $late = $task->due_at->isPast();

                if (AlertGate::once(($late ? 'task-late:' : 'task-due:').$task->id)) {
                    ImportantAlerts::aboutTask($late ? 'task_overdue' : 'task_due', $task);

                    if ($task->lead) {
                        LeadSignal::dispatch($task->lead, $late ? 'lead.task_overdue' : 'lead.task_due', ['task' => $task->uuid]);
                    }
                }
            });

        Reservation::query()->with('lead')->where('status', 'pending')->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDay()])
            ->each(function (Reservation $reservation) {
                if ($reservation->lead && AlertGate::once("reservation-expiring:{$reservation->id}")) {
                    ImportantAlerts::aboutLead('reservation_expiring', $reservation->lead, [], true);
                    LeadSignal::dispatch($reservation->lead, 'lead.reservation_expiring', ['reservation' => $reservation->uuid]);
                }
            });
    }
}
