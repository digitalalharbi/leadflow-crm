<?php

namespace App\Domain\Sla;

use App\Domain\Leads\InterestSla;
use App\Domain\Notifications\AlertGate;
use App\Enums\ActivityType;
use App\Enums\Role;
use App\Events\LeadSignal;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ImportantNotification;
use App\Services\Leads\ActivityLogger;
use App\Support\TenantContext;

/**
 * Tells somebody when an interested lead is about to wait too long — and again, louder, when they have.
 *
 * Each step is said once per lead per time the clock started (AlertGate), so a sweep that runs
 * every five minutes does not nag: the owner hears "due soon", then "late"; the managers hear
 * "late" only, and only because the owner has had their chance.
 */
class InterestSlaSweeper
{
    private const LABELS = ['hot' => 'ساخن', 'interested' => 'مهتم', 'visit_ready' => 'جاهز للزيارة', 'reservation_ready' => 'جاهز للحجز'];

    public function __construct(private TenantContext $context, private ActivityLogger $activities) {}

    /** @return array{due_soon:int, breached:int} */
    public function sweep(Tenant $tenant): array
    {
        $counts = ['due_soon' => 0, 'breached' => 0];
        $this->context->set($tenant);

        $managers = User::query()->whereIn('role', [Role::Admin->value, Role::Manager->value])->where('is_active', true)->get();

        Lead::query()
            ->whereIn('interest_level', array_keys(InterestSla::KEYS))
            ->whereNotNull('assigned_to')
            ->with('assignee')
            ->orderBy('interest_level_changed_at')
            ->limit(500)
            ->get()
            ->each(function (Lead $lead) use (&$counts, $managers) {
                $sla = InterestSla::for($lead);

                if (! $sla || ! in_array($sla['state'], ['due_soon', 'breached'], true)) {
                    return;
                }

                // One alert per lead per start of the clock and per step.
                $key = "interest_sla:{$sla['state']}:{$lead->id}:".strtotime($sla['since']);

                if (! AlertGate::once($key)) {
                    return;
                }

                $label = self::LABELS[$sla['level']];
                $late = $sla['state'] === 'breached';

                $lead->assignee?->notify(new ImportantNotification(
                    $late ? 'interest_sla_breached' : 'interest_sla_due_soon',
                    $late ? "عميل {$label} ينتظر ردًّا" : "عميل {$label} يقترب موعده",
                    $late
                        ? "{$lead->full_name} تجاوز مهلة {$sla['target_minutes']} دقيقة — تواصل معه الآن."
                        : "{$lead->full_name} — تبقّى {$sla['minutes_left']} دقيقة.",
                    $lead->uuid, null, $late ? 'critical' : 'warning',
                ));

                if (! $late) {
                    $counts['due_soon']++;

                    return;
                }

                $counts['breached']++;
                $this->activities->log($lead, ActivityType::NoteAdded, null,
                    "تجاوز مهلة العميل ({$label}): {$sla['target_minutes']} دقيقة",
                    ['by' => 'sla', 'sla' => 'interest_breached', 'level' => $sla['level']]);

                foreach ($managers->reject(fn (User $user) => $user->id === $lead->assigned_to) as $manager) {
                    $manager->notify(new ImportantNotification(
                        'interest_sla_escalated', "تصعيد: عميل {$label} بلا رد",
                        "{$lead->full_name} عند {$lead->assignee?->name} تجاوز مهلته.", $lead->uuid, null, 'critical',
                    ));
                }

                LeadSignal::dispatch($lead, 'lead.sla_breached', ['interest_level' => $sla['level']]);
            });

        return $counts;
    }
}
