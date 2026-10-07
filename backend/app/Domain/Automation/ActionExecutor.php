<?php

namespace App\Domain\Automation;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LostReason;
use App\Models\User;
use App\Notifications\AutomationNotification;
use App\Services\Leads\ActivityLogger;
use App\Services\Leads\LeadAssignmentService;
use App\Services\Leads\LeadScoringService;
use Illuminate\Support\Carbon;

/**
 * Carries out what a rule decided.
 *
 * Each action returns a short, human sentence describing what it did, and that
 * sentence is what the trace shows — "أُسند إلى نورة الشمري", not
 * `{"action":"assign","user_id":42}`. The person reading a trace is usually a
 * manager asking why a customer got a message, so the record has to be prose.
 *
 * In Arabic, because that is the language of the product and of the person
 * reading it. These strings went out in English first and looked perfectly
 * reasonable in a test assertion; on the lead screen, under an Arabic heading,
 * "Score 38 → 53." is the sentence a Saudi sales manager is left to interpret.
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
                'notify' => $this->notify($lead, $action),
                'stop_follow_ups' => $this->stopFollowUps($lead),
                default => ['ok' => false, 'description' => "إجراء غير معروف '{$type}' — لم يُنفَّذ شيء."],
            };
        } catch (\Throwable $e) {
            // The message, never the trace: a rule's failure is shown in the UI.
            return ['ok' => false, 'description' => "فشل الإجراء '{$type}': ".$e->getMessage()];
        }
    }

    private function assign(Lead $lead, array $action): array
    {
        $strategy = (string) ($action['strategy'] ?? 'round_robin');

        $user = match ($strategy) {
            'specific' => User::where('uuid', $action['user_id'] ?? '')->first(),
            default => $this->assignment->byStrategy($lead->tenant),
        };

        if (! $user) {
            return ['ok' => false, 'description' => 'لا يوجد مندوب متاح للإسناد.'];
        }

        // Never reassign a lead someone is already working: automation that
        // moves work out from under a rep is worse than no automation.
        if ($lead->assigned_to !== null) {
            return ['ok' => true, 'description' => "العميل مُسند بالفعل إلى {$lead->assignee?->name} — لم يُغيَّر."];
        }

        $this->assignment->assign($lead, $user, null, true);

        return ['ok' => true, 'description' => "أُسند إلى {$user->name}."];
    }

    private function changeStage(Lead $lead, array $action): array
    {
        $to = LeadStatus::tryFrom((string) ($action['status'] ?? ''));

        if (! $to) {
            return ['ok' => false, 'description' => 'هذه المرحلة غير موجودة.'];
        }

        if ($lead->status === $to) {
            return ['ok' => true, 'description' => "العميل في مرحلة {$to->value} أصلًا."];
        }

        /*
         * A rule may not close a lead as lost without saying why.
         *
         * The model backfills the timestamps a closed lead must carry, because
         * `now()` is an approximation it can defend. A lost reason is not: it is
         * the thing the lost-reasons report is made of, and a value invented
         * here would be indistinguishable from one a rep chose. So the rule
         * fails loudly instead, and the trace says what was missing.
         */
        if ($to === LeadStatus::Lost && blank($action['lost_reason_id'] ?? null)) {
            return ['ok' => false, 'description' => 'لا يمكن إغلاق العميل كمفقود دون تحديد السبب.'];
        }

        if ($to === LeadStatus::Lost) {
            $reason = LostReason::where('uuid', $action['lost_reason_id'])->first();

            if (! $reason) {
                return ['ok' => false, 'description' => 'سبب الفقد المحدد في القاعدة غير موجود.'];
            }

            $lead->lost_reason_id = $reason->id;
        }

        $from = $lead->status;
        $lead->forceFill(['status' => $to])->save();
        $this->activities->log($lead, ActivityType::StatusChanged, null,
            "من {$from->value} إلى {$to->value}",
            ['from' => $from->value, 'to' => $to->value, 'by' => 'automation']);

        return ['ok' => true, 'description' => "نُقل من {$from->value} إلى {$to->value}."];
    }

    private function adjustScore(Lead $lead, array $action): array
    {
        $by = (int) ($action['by'] ?? 0);

        if ($by === 0) {
            return ['ok' => false, 'description' => 'لم يُحدَّد مقدار التغيير في التقييم.'];
        }

        // Clamped, so a rule cannot drive a lead to 900 or below zero.
        $before = (int) $lead->score;
        $after = max(0, min(100, $before + $by));

        $lead->forceFill(['score' => $after])->save();
        $this->scoring->apply($lead);

        return ['ok' => true, 'description' => "التقييم {$before} ← {$after}."];
    }

    private function addTag(Lead $lead, array $action): array
    {
        $tag = trim((string) ($action['tag'] ?? ''));

        if ($tag === '') {
            return ['ok' => false, 'description' => 'لم يُحدَّد وسم.'];
        }

        $this->activities->log($lead, ActivityType::NoteAdded, null,
            "وسم آلي: {$tag}", ['tag' => $tag, 'by' => 'automation']);

        return ['ok' => true, 'description' => "أُضيف الوسم '{$tag}'."];
    }

    private function scheduleFollowUp(Lead $lead, array $action): array
    {
        /*
         * Hours or minutes. The builder and the templates both speak in hours,
         * because a manager thinks "follow up in two days", not "in 2880
         * minutes" — and for a while they wrote `in_hours` while this read only
         * `in_minutes`, so every rule that scheduled a follow-up reported a
         * failure nobody was looking at. Both are accepted now, and the
         * validator refuses a rule carrying neither, so the mismatch cannot
         * come back as silence.
         */
        $minutes = (int) ($action['in_minutes'] ?? 0)
            + (int) ($action['in_hours'] ?? 0) * 60;

        if ($minutes <= 0) {
            return ['ok' => false, 'description' => 'المتابعة تحتاج مهلة أكبر من صفر.'];
        }

        // One pending automated follow-up at a time: a rule that fires on every
        // reply would otherwise bury the rep in duplicates.
        $existing = FollowUp::where('lead_id', $lead->id)
            ->where('status', 'pending')
            ->exists();

        if ($existing) {
            return ['ok' => true, 'description' => 'توجد متابعة مفتوحة بالفعل — لم تُنشأ ثانية.'];
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

        return ['ok' => true, 'description' => 'حُدِّدت متابعة في '.$due->toDayDateTimeString().'.'];
    }

    private function addNote(Lead $lead, array $action): array
    {
        $body = trim((string) ($action['body'] ?? ''));

        if ($body === '') {
            return ['ok' => false, 'description' => 'الملاحظة فارغة — لم تُضَف.'];
        }

        $this->activities->log($lead, ActivityType::NoteAdded, null, $body, ['by' => 'automation']);

        return ['ok' => true, 'description' => 'أُضيفت ملاحظة.'];
    }

    /**
     * Tells a person, not a channel.
     *
     * "owner" is the rep carrying the lead and "managers" is everyone who can
     * see the whole workspace. A rule that notifies an unassigned lead's owner
     * reports that it could not rather than silently notifying nobody — the
     * failure mode this is guarding against is a workspace believing it is
     * being alerted when it is not.
     */
    private function notify(Lead $lead, array $action): array
    {
        $audience = (string) ($action['audience'] ?? 'owner');
        $message = trim((string) ($action['message'] ?? ''))
            ?: "يحتاج {$lead->full_name} إلى متابعة.";
        $ruleName = (string) ($action['rule_name'] ?? 'قاعدة تلقائية');

        $recipients = match ($audience) {
            'managers' => User::where('tenant_id', $lead->tenant_id)
                ->whereIn('role', [Role::Admin->value, Role::Manager->value])
                ->where('is_active', true)
                ->get(),
            default => $lead->assigned_to
                ? User::where('id', $lead->assigned_to)->where('is_active', true)->get()
                : collect(),
        };

        if ($recipients->isEmpty()) {
            return ['ok' => false, 'description' => $audience === 'owner'
                ? 'لم يُرسل تنبيه: لا مسؤول على هذا العميل.'
                : 'لم يُرسل تنبيه: لا أحد نشط في هذه الفئة.'];
        }

        foreach ($recipients as $recipient) {
            $recipient->notify(new AutomationNotification($lead, $message, $ruleName));
        }

        return ['ok' => true, 'description' => $audience === 'managers'
            ? 'أُرسل تنبيه إلى '.$recipients->count().' من الإدارة.'
            : 'أُرسل تنبيه إلى مسؤول العميل.'];
    }

    /**
     * Closes the open steps on a lead that is finished.
     *
     * The action exists because the alternative is a workspace whose reps are
     * reminded for weeks to chase customers who already bought or already said
     * no. Nothing else in the product cancels a step automatically, so a rule
     * is the only way a workspace can express "stop".
     */
    private function stopFollowUps(Lead $lead): array
    {
        // One at a time rather than a mass update: a query-builder update fires
        // no model events, and the lead's `next_follow_up_at` is derived from
        // those — so the lead would still have pointed at a cancelled reminder.
        $pending = FollowUp::where('lead_id', $lead->id)
            ->where('status', FollowUp::PENDING)
            ->get();

        $pending->each(fn (FollowUp $followUp) => $followUp->update(['status' => FollowUp::CANCELLED]));

        $cancelled = $pending->count();

        return $cancelled === 0
            ? ['ok' => true, 'description' => 'لا توجد متابعات مفتوحة لإيقافها.']
            : ['ok' => true, 'description' => "أُوقفت {$cancelled} متابعة مفتوحة."];
    }
}
