<?php

namespace App\Domain\Automation;

use App\Domain\Files\FileForTheLead;
use App\Domain\Files\ShareFile;
use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LostReason;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Notifications\AutomationNotification;
use App\Services\Leads\ActivityLogger;
use App\Services\Leads\LeadAssignmentService;
use App\Services\Leads\LeadScoringService;
use App\Services\Whatsapp\TemplateRenderer;
use App\Services\Whatsapp\WhatsappSender;
use Illuminate\Support\Carbon;
use RuntimeException;

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
        private WhatsappSender $whatsapp,
        private TemplateRenderer $renderer,
        private FileForTheLead $chooser,
        private ShareFile $sharer,
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
                'send_whatsapp' => $this->sendWhatsapp($lead, $action),
                'share_file' => $this->shareFile($lead, $action),
                'stop_follow_ups' => $this->stopFollowUps($lead, $action),
                default => ['ok' => false, 'description' => "إجراء غير معروف '{$type}' — لم يُنفَّذ شيء."],
            };
        } catch (\Throwable $e) {
            // The message, never the trace: a rule's failure is shown in the UI.
            return ['ok' => false, 'description' => "فشل الإجراء '{$type}': ".$e->getMessage()];
        }
    }

    /**
     * What every activity a rule writes carries.
     *
     * `by` was already here and the interface never showed it, so a stage moved
     * by a rule and a stage moved by a colleague read identically — and the
     * first question anybody asks about an automated change is which rule did
     * it. The engine puts the rule's name on the action before executing it, so
     * the name reaches here without the executor having to know about rules.
     */
    private function signature(array $action): array
    {
        return array_filter([
            'by' => 'automation',
            'rule_name' => $action['rule_name'] ?? null,
        ], fn ($value) => $value !== null);
    }

    private function assign(Lead $lead, array $action): array
    {
        $strategy = (string) ($action['strategy'] ?? 'round_robin');

        $user = match ($strategy) {
            /*
             * Scoped to the lead's own workspace, by hand.
             *
             * `User` is deliberately not covered by the tenant scope — the
             * login lookup has to run before a tenant context exists — and its
             * docblock says filtering is done "in the controller/policy layer".
             * This is neither, so a rule naming any uuid would hand the lead to
             * somebody in another workspace, who would then own and see it.
             *
             * `is_active` too: a rule that outlives the rep it names must not
             * quietly park leads on a disabled account.
             */
            'specific' => User::where('uuid', $action['user_id'] ?? '')
                ->where('tenant_id', $lead->tenant_id)
                ->where('is_active', true)
                ->first(),
            // The lead is passed so the narrowing steps apply — the project's
            // own team, then the language the number says, then the city.
            default => $this->assignment->byStrategy($lead->tenant, $lead),
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
            ['from' => $from->value, 'to' => $to->value] + $this->signature($action));

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
            "وسم آلي: {$tag}", ['tag' => $tag] + $this->signature($action));

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

        /*
         * `follow_up_created` was logged only by the follow-ups screen, so the
         * two ways a follow-up is actually booked — a rule, or the rep's own
         * outcome in the work queue — left no trace at all. The lead's story
         * jumped from "no answer" to a reminder that appeared from nowhere.
         */
        $this->activities->log($lead, ActivityType::FollowUpCreated, null,
            $action['note'] ?? null,
            $this->signature($action) + ['due_at' => $due->toIso8601String()]);

        return ['ok' => true, 'description' => 'حُدِّدت متابعة في '.$due->toDayDateTimeString().'.'];
    }

    private function addNote(Lead $lead, array $action): array
    {
        $body = trim((string) ($action['body'] ?? ''));

        if ($body === '') {
            return ['ok' => false, 'description' => 'الملاحظة فارغة — لم تُضَف.'];
        }

        $this->activities->log($lead, ActivityType::NoteAdded, null, $body, $this->signature($action));

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
    /**
     * Sends the workspace's own saved message to the lead.
     *
     * The mechanism for this existed and only a person could reach it: every
     * send went through `LeadMessageController`, so a qualification sequence —
     * the thing automation is for — was a rep doing it by hand twenty times a
     * day. The sender is unchanged, which is the point: a rule sending a
     * message obeys the same opt-out, the same per-lead rate limit and the same
     * "record it before the provider is called" ordering a rep does, because it
     * is the same code.
     *
     * The wording lives in a template rather than in the rule. A manager who
     * wants to change what the product says to people should change it in the
     * one place that lists what the product says to people, not by opening six
     * rules to find which of them carries that sentence.
     */
    private function sendWhatsapp(Lead $lead, array $action): array
    {
        $uuid = (string) ($action['template_uuid'] ?? '');

        if ($uuid === '') {
            return ['ok' => false, 'description' => 'لم تُحدَّد رسالة — لم يُرسل شيء.'];
        }

        $template = MessageTemplate::where('uuid', $uuid)->first();

        if (! $template) {
            return ['ok' => false, 'description' => 'الرسالة المحددة غير موجودة — لم يُرسل شيء.'];
        }

        if (! $template->is_active) {
            return ['ok' => false, 'description' => "الرسالة '{$template->name}' موقوفة — لم تُرسل."];
        }

        /*
         * No actor. A rule is not a person, and attributing the message to the
         * lead's owner would put their name on something they did not write —
         * on the screen and in the audit trail both.
         */
        $body = $this->renderer->render($template->body, $lead, null);

        try {
            $this->whatsapp->send($lead, $body, null, $template->name);
        } catch (RuntimeException $e) {
            /*
             * Reported, not thrown. The reasons a send is refused are ordinary
             * operational facts — the lead opted out, the workspace has not
             * connected WhatsApp, there is no usable number — and each one is
             * something a manager reading the trace can act on. None of them is
             * a reason to abandon the rest of the rule.
             */
            return ['ok' => false, 'description' => 'لم تُرسل الرسالة: '.$e->getMessage()];
        }

        // The sender writes the timeline entry itself, so there is one record
        // of a message whether a rep or a rule sent it.
        return ['ok' => true, 'description' => "أُرسلت رسالة واتساب '{$template->name}'."];
    }

    /**
     * Hands the lead the file their own answers point at.
     *
     * `FileForTheLead` already decides which one and says why; it was reachable
     * only from the button a rep presses. A share is created either way, so the
     * link is on the lead screen and its opens are counted, and the delivery is
     * reported separately from the choice — a workspace with no WhatsApp still
     * gets the link, and is told that is all it got.
     */
    private function shareFile(Lead $lead, array $action): array
    {
        $kind = ($action['kind'] ?? null) ?: null;

        ['file' => $file, 'reason' => $reason] = $this->chooser->pick($lead, $kind);

        if (! $file) {
            return ['ok' => false, 'description' => match ($reason) {
                'no_files_of_kind' => 'لا يوجد ملف من هذا النوع لمشروع العميل.',
                default => 'لا توجد ملفات لمشروع هذا العميل — لم يُرسل شيء.',
            }];
        }

        $share = $this->sharer->forLead($file, $lead, null, 'automation');

        if (! ($action['send'] ?? true)) {
            return ['ok' => true, 'description' => "جُهِّز رابط '{$file->name}' للعميل."];
        }

        try {
            $this->whatsapp->send($lead, trim($file->name."\n".$share->url()), null);
        } catch (RuntimeException $e) {
            // The share stands. It is on the lead's screen for a rep to send by
            // hand, which is strictly better than having neither.
            return ['ok' => false, 'description' => "جُهِّز رابط '{$file->name}' ولم يُرسل: ".$e->getMessage()];
        }

        return ['ok' => true, 'description' => "أُرسل '{$file->name}' إلى العميل."];
    }

    private function stopFollowUps(Lead $lead, array $action): array
    {
        // One at a time rather than a mass update: a query-builder update fires
        // no model events, and the lead's `next_follow_up_at` is derived from
        // those — so the lead would still have pointed at a cancelled reminder.
        $pending = FollowUp::where('lead_id', $lead->id)
            ->where('status', FollowUp::PENDING)
            ->get();

        $pending->each(fn (FollowUp $followUp) => $followUp->update(['status' => FollowUp::CANCELLED]));

        $cancelled = $pending->count();

        // A lead that stops being chased stops for a reason, and the reason is
        // a rule the reader cannot see from anywhere else on this screen.
        if ($cancelled > 0) {
            $this->activities->log($lead, ActivityType::NoteAdded, null,
                "أُوقفت {$cancelled} متابعة مفتوحة.", $this->signature($action));
        }

        return $cancelled === 0
            ? ['ok' => true, 'description' => 'لا توجد متابعات مفتوحة لإيقافها.']
            : ['ok' => true, 'description' => "أُوقفت {$cancelled} متابعة مفتوحة."];
    }
}
