<?php

namespace App\Domain\Automation;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use App\Services\Leads\ActivityLogger;
use App\Services\Leads\LeadAssignmentService;
use App\Services\Leads\LeadScoringService;
use Illuminate\Support\Carbon;

/**
 * Carries out what a rule decided.
 *
 * Each action returns a short, human sentence describing what it did, and that
 * sentence is what the trace shows — "assigned to نورة الشمري", not
 * `{"action":"assign","user_id":42}`. The person reading a trace is usually a
 * manager asking why a customer got a message, so the record has to be prose.
 *
 * An action that cannot be carried out is reported, not thrown: one broken
 * action must not abandon the rest of the rule, and must never lose the lead.
 */
class ActionExecutor
{
    public function __construct(
        private LeadAssignmentService $assignment,
        private LeadScoringService $scoring,
        private ActivityLogger $activities,
    ) {}

    /**
     * @param  array{type?:string}  $action
     * @return array{ok:bool, description:string}
     */
    public function execute(Lead $lead, array $action): array
    {
        $type = (string) ($action['type'] ?? '');

        try {
            return match ($type) {
                'assign' => $this->assign($lead, $action),
                'change_stage' => $this->changeStage($lead, $action),
                'adjust_score' => $this->adjustScore($lead, $action),
                'add_tag' => $this->addTag($lead, $action),
                'schedule_follow_up' => $this->scheduleFollowUp($lead, $action),
                'add_note' => $this->addNote($lead, $action),
                default => ['ok' => false, 'description' => "Unknown action '{$type}' — nothing was done."],
            };
        } catch (\Throwable $e) {
            // The message, never the trace: a rule's failure is shown in the UI.
            return ['ok' => false, 'description' => "Action '{$type}' failed: ".$e->getMessage()];
        }
    }

    private function assign(Lead $lead, array $action): array
    {
        $strategy = (string) ($action['strategy'] ?? 'round_robin');

        $user = match ($strategy) {
            'specific' => User::where('uuid', $action['user_id'] ?? '')->first(),
            default => $this->assignment->roundRobin($lead->tenant),
        };

        if (! $user) {
            return ['ok' => false, 'description' => 'No eligible salesperson was available to assign.'];
        }

        // Never reassign a lead someone is already working: automation that
        // moves work out from under a rep is worse than no automation.
        if ($lead->assigned_to !== null) {
            return ['ok' => true, 'description' => "Already assigned to {$lead->assignee?->name}; left alone."];
        }

        $this->assignment->assign($lead, $user, null, true);

        return ['ok' => true, 'description' => "Assigned to {$user->name}."];
    }

    private function changeStage(Lead $lead, array $action): array
    {
        $to = LeadStatus::tryFrom((string) ($action['status'] ?? ''));

        if (! $to) {
            return ['ok' => false, 'description' => 'That stage does not exist.'];
        }

        if ($lead->status === $to) {
            return ['ok' => true, 'description' => "Already at {$to->value}."];
        }

        $from = $lead->status;
        $lead->forceFill(['status' => $to])->save();
        $this->activities->log($lead, ActivityType::StatusChanged, null,
            "من {$from->value} إلى {$to->value}",
            ['from' => $from->value, 'to' => $to->value, 'by' => 'automation']);

        return ['ok' => true, 'description' => "Stage moved from {$from->value} to {$to->value}."];
    }

    private function adjustScore(Lead $lead, array $action): array
    {
        $by = (int) ($action['by'] ?? 0);

        if ($by === 0) {
            return ['ok' => false, 'description' => 'No score change was specified.'];
        }

        // Clamped, so a rule cannot drive a lead to 900 or below zero.
        $before = (int) $lead->score;
        $after = max(0, min(100, $before + $by));

        $lead->forceFill(['score' => $after])->save();
        $this->scoring->apply($lead);

        return ['ok' => true, 'description' => "Score {$before} → {$after}."];
    }

    private function addTag(Lead $lead, array $action): array
    {
        $tag = trim((string) ($action['tag'] ?? ''));

        if ($tag === '') {
            return ['ok' => false, 'description' => 'No tag was specified.'];
        }

        $this->activities->log($lead, ActivityType::NoteAdded, null,
            "وسم آلي: {$tag}", ['tag' => $tag, 'by' => 'automation']);

        return ['ok' => true, 'description' => "Tagged '{$tag}'."];
    }

    private function scheduleFollowUp(Lead $lead, array $action): array
    {
        $minutes = (int) ($action['in_minutes'] ?? 0);

        if ($minutes <= 0) {
            return ['ok' => false, 'description' => 'A follow-up needs a positive delay.'];
        }

        // One pending automated follow-up at a time: a rule that fires on every
        // reply would otherwise bury the rep in duplicates.
        $existing = FollowUp::where('lead_id', $lead->id)
            ->where('status', 'pending')
            ->exists();

        if ($existing) {
            return ['ok' => true, 'description' => 'A follow-up is already pending; no second one created.'];
        }

        $due = Carbon::now()->addMinutes($minutes);

        FollowUp::create([
            'lead_id' => $lead->id,
            'assigned_to' => $lead->assigned_to,
            'due_at' => $due,
            'note' => $action['note'] ?? null,
            'status' => 'pending',
        ]);

        $lead->forceFill(['next_follow_up_at' => $due])->save();

        return ['ok' => true, 'description' => 'Follow-up scheduled for '.$due->toDayDateTimeString().'.'];
    }

    private function addNote(Lead $lead, array $action): array
    {
        $body = trim((string) ($action['body'] ?? ''));

        if ($body === '') {
            return ['ok' => false, 'description' => 'An empty note was not added.'];
        }

        $this->activities->log($lead, ActivityType::NoteAdded, null, $body, ['by' => 'automation']);

        return ['ok' => true, 'description' => 'Note added.'];
    }
}
