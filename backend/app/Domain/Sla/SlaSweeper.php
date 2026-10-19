<?php

namespace App\Domain\Sla;

use App\Domain\Automation\ProjectSettings;
use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Events\LeadSignal;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\SlaBreachNotification;
use App\Services\Leads\ActivityLogger;
use App\Services\Leads\LeadAssignmentService;
use App\Support\TenantContext;
use Illuminate\Support\Collection;

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
            ->with('project:id,automation_settings')
            ->orderBy('created_at')
            ->limit(500)
            ->get();

        /*
         * Measured in working minutes, not wall-clock ones.
         *
         * A lead arriving at two in the morning used to be reminded at ten
         * past, escalated to the management at half past, and taken off its rep
         * by three — for a call nobody could have made. The rep opened their
         * laptop to a breach they could not have avoided, which is the fastest
         * way to teach a team that the SLA is noise.
         *
         * A workspace that has not said when it works is always open, so this
         * is the same number it has always been until somebody says otherwise.
         */
        $hours = BusinessHours::for($settings);

        foreach ($candidates as $lead) {
            $age = $hours->minutesBetween($lead->created_at, now());

            // A project with its own first-contact target is held to it: its rungs come
            // earlier, or later, in the same proportion as its target does.
            $workspaceTarget = (int) ($settings->first_contact_target_minutes ?: 30);
            $projectTarget = ProjectSettings::firstContactTarget($lead, $workspaceTarget);
            $age = (int) round($age * $workspaceTarget / max(1, $projectTarget));

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
            ['by' => 'sla', 'sla' => 'remind', 'age_minutes' => $age]);

        /*
         * The rung that reaches a person.
         *
         * This wrote "تذكير آلي" into the lead's timeline and sent nothing, so
         * the reminder arrived only for somebody already looking at the lead
         * they had not contacted. A reminder nobody receives is not a reminder,
         * and the whole ladder exists because nobody is looking.
         */
        $this->tell($lead->assignee ? [$lead->assignee] : [], $lead, SlaBreachNotification::REMIND, $age);
        LeadSignal::dispatch($lead, 'lead.sla_breached', ['age_minutes' => $age]);

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
            ['by' => 'sla', 'sla' => 'escalate', 'age_minutes' => $age]);

        // It said "للإدارة" and told the management nothing. An escalation that
        // only the escalated-about can see is not an escalation.
        $this->tell($this->management($lead), $lead, SlaBreachNotification::ESCALATE, $age);

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
            ['by' => 'sla', 'sla' => 'reassign', 'from' => $previous, 'to' => $next->id, 'age_minutes' => $age]);

        return 1;
    }

    /**
     * @param  iterable<User>  $people
     */
    private function tell(iterable $people, Lead $lead, string $stage, int $age): void
    {
        foreach ($people as $person) {
            $person->notify(new SlaBreachNotification($lead, $stage, $age));
        }
    }

    /**
     * Who hears an escalation.
     *
     * The owner is left out on purpose: they have already had the reminder,
     * and the point of this rung is that somebody else now knows.
     *
     * @return Collection<int, User>
     */
    private function management(Lead $lead): Collection
    {
        return User::where('tenant_id', $lead->tenant_id)
            ->whereIn('role', [Role::Admin->value, Role::Manager->value])
            ->where('is_active', true)
            ->when($lead->assigned_to, fn ($q) => $q->where('id', '!=', $lead->assigned_to))
            ->get();
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
