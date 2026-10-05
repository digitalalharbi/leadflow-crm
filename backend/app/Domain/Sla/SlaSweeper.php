<?php

namespace App\Domain\Sla;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Leads\ActivityLogger;
use App\Services\Leads\LeadAssignmentService;
use App\Support\TenantContext;

/**
 * Finds leads nobody has contacted and walks them up the escalation ladder.
 *
 * The product's premise is that a lead is never silently ignored, and this is
 * the only part of the system that can notice silence — everything else reacts
 * to something happening.
 *
 * Each rung fires at most once per lead, recorded on the lead rather than
 * inferred from timestamps: a sweep that runs twice, or a clock that moves,
 * must not produce two reminders for one breach.
 */
class SlaSweeper
{
    public function __construct(
        private LeadAssignmentService $assignment,
        private ActivityLogger $activities,
        private TenantContext $context,
    ) {}

    /** @return array{reminded:int, escalated:int, reassigned:int} */
    public function sweep(Tenant $tenant): array
    {
        $settings = $tenant->settings;
        $counts = ['reminded' => 0, 'escalated' => 0, 'reassigned' => 0];

        if (! $settings) {
            return $counts;
        }

        $this->context->set($tenant);

        // Open, owned, and never contacted. A lead nobody owns is a different
        // problem — it already shows as unassigned, and reassigning it here
        // would hide that rather than fix it.
        $candidates = Lead::query()
            ->whereNull('first_contact_at')
            ->whereNotNull('assigned_to')
            ->whereIn('status', [LeadStatus::New->value, LeadStatus::Contacted->value])
            ->orderBy('created_at')
            ->limit(500)
            ->get();

        foreach ($candidates as $lead) {
            $age = (int) $lead->created_at->diffInMinutes(now());

            $counts['reassigned'] += $this->maybeReassign($lead, $settings, $age);
            $counts['escalated'] += $this->maybeEscalate($lead, $settings, $age);
            $counts['reminded'] += $this->maybeRemind($lead, $settings, $age);
        }

        return $counts;
    }

    private function maybeRemind(Lead $lead, $settings, int $age): int
    {
        $after = (int) $settings->sla_remind_after_minutes;

        if ($after <= 0 || $age < $after || $lead->sla_reminded_at) {
            return 0;
        }

        $lead->forceFill(['sla_reminded_at' => now()])->saveQuietly();
        $this->activities->log($lead, ActivityType::NoteAdded, null,
            "تذكير آلي: لم يتم التواصل بعد {$age} دقيقة",
            ['sla' => 'remind', 'age_minutes' => $age]);

        return 1;
    }

    private function maybeEscalate(Lead $lead, $settings, int $age): int
    {
        $after = (int) $settings->sla_escalate_after_minutes;

        if ($after <= 0 || $age < $after || $lead->sla_escalated_at) {
            return 0;
        }

        $lead->forceFill(['sla_escalated_at' => now()])->saveQuietly();
        $this->activities->log($lead, ActivityType::NoteAdded, null,
            "تصعيد آلي للإدارة: {$age} دقيقة بلا تواصل",
            ['sla' => 'escalate', 'age_minutes' => $age]);

        return 1;
    }

    /**
     * The last rung: take the lead back and give it to someone else. Skipped
     * when there is nobody else — moving a lead from the only rep to the only
     * rep churns the timeline and changes nothing.
     */
    private function maybeReassign(Lead $lead, $settings, int $age): int
    {
        $after = (int) $settings->sla_reassign_after_minutes;

        if ($after <= 0 || $age < $after || $lead->sla_reassigned_at) {
            return 0;
        }

        $previous = $lead->assigned_to;
        $next = $this->nextAssignee($lead);

        if (! $next || $next->id === $previous) {
            return 0;
        }

        $lead->forceFill(['sla_reassigned_at' => now()])->saveQuietly();
        $this->assignment->assign($lead, $next, null, true);

        $this->activities->log($lead, ActivityType::Assigned, null,
            "إعادة إسناد آلية بعد {$age} دقيقة بلا تواصل",
            ['sla' => 'reassign', 'from' => $previous, 'to' => $next->id, 'age_minutes' => $age]);

        return 1;
    }

    /** Someone other than the current owner, by round robin. */
    private function nextAssignee(Lead $lead): ?User
    {
        $tenant = $lead->tenant;

        if (! $tenant) {
            return null;
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $candidate = $this->assignment->roundRobin($tenant);

            if (! $candidate) {
                return null;
            }

            if ($candidate->id !== $lead->assigned_to) {
                return $candidate;
            }
        }

        return null;
    }
}
