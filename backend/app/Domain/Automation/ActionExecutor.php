<?php

namespace App\Domain\Automation;

use App\Domain\Conversation\QualificationCompleter;
use App\Domain\Files\FileDelivery;
use App\Domain\Files\FileForTheLead;
use App\Domain\Files\ShareFile;
use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Enums\MessageStatus;
use App\Enums\Role;
use App\Jobs\RunScheduledAction;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LostReason;
use App\Models\MessageTemplate;
use App\Models\ScheduledAction;
use App\Models\User;
use App\Notifications\AutomationNotification;
use App\Services\Email\EmailSender;
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
        private EmailSender $email,
        private FileForTheLead $chooser,
        private ShareFile $sharer,
        private FileDelivery $delivery,
        private TemplatePicker $picker,
        private Guardrails $guardrails,
    ) {}

    /**
     * @param  array{type?:string}  $action
     * @return array{ok:bool, description:string}
     */
    public function execute(Lead $lead, array $action): array
    {
        $type = (string) ($action['type'] ?? '');

        try {
            // Whatever the rule says, automation does not write to somebody it
            // must not — see Guardrails.
            if ($held = $this->held($lead, $action)) {
                return $held;
            }

            $result = match ($type) {
                'assign' => $this->assign($lead, $action),
                'change_stage' => $this->changeStage($lead, $action),
                'adjust_score' => $this->adjustScore($lead, $action),
                'add_tag' => $this->addTag($lead, $action),
                'schedule_follow_up' => $this->scheduleFollowUp($lead, $action),
                'add_note' => $this->addNote($lead, $action),
                'notify' => $this->notify($lead, $action),
                'send_whatsapp' => $this->sendWhatsapp($lead, $action),
                'send_email' => $this->sendEmail($lead, $action),
                'share_file' => $this->shareFile($lead, $action),
                'stop_follow_ups' => $this->stopFollowUps($lead, $action),
                'ask_qualification' => $this->askQualification($lead, $action),
                'set_priority' => $this->setPriority($lead, $action),
                'hand_off' => $this->handOff($lead, $action),
                'set_temperature' => $this->setTemperature($lead, $action),
                'stop_automation' => $this->stopAutomation($lead, $action),
                'start_nurture' => $this->startNurture($lead, $action),
                'create_call_task' => $this->createCallTask($lead, $action),
                'request_appointment' => $this->sendWhatsapp($lead, ['purpose' => 'appointment'] + $action),
                default => ['ok' => false, 'description' => "إجراء غير معروف '{$type}' — لم يُنفَّذ شيء."],
            };

            // A channel that failed is not the end: the other channel, then a person.
            return app(Recovery::class)->attempt($lead, $action, $result, fn (Lead $l, array $a) => $this->execute($l, $a)) ?? $result;
        } catch (\Throwable $e) {
            // The message, never the trace: a rule's failure is shown in the UI.
            return ['ok' => false, 'description' => "فشل الإجراء '{$type}': ".$e->getMessage()];
        }
    }

    /**
     * The guardrails, applied to the actions that write to a person.
     *
     * @return ?array{ok:bool, result:string, description:string}
     */
    private function held(Lead $lead, array $action): ?array
    {
        $type = (string) ($action['type'] ?? '');

        $channel = match ($type) {
            'send_whatsapp', 'ask_qualification', 'request_appointment', 'start_nurture' => 'whatsapp',
            'send_email' => 'email',
            'share_file' => ($action['send'] ?? true) ? (($action['channel'] ?? 'whatsapp') === 'email' ? 'email' : 'whatsapp') : null,
            default => null,
        };

        if ($channel === null) {
            return null;
        }

        $template = null;
        if (in_array($type, ['send_whatsapp', 'send_email', 'request_appointment', 'start_nurture'], true)) {
            $found = $this->template($lead, $channel, $type === 'request_appointment' ? ['purpose' => 'appointment'] + $action : ($type === 'start_nurture' ? ['purpose' => 'nurture'] + $action : $action));

            // A message that cannot be found is the action's own error to report.
            if (! $found instanceof MessageTemplate) {
                return null;
            }

            $template = $found;
        }

        $verdict = $this->guardrails->check($lead, $channel, $action, $template?->name, $template?->meta_template_name);

        if ($verdict['verdict'] === Guardrails::ALLOW) {
            return null;
        }

        if ($verdict['verdict'] === Guardrails::BLOCK) {
            return ['ok' => true, 'result' => 'skipped', 'description' => $verdict['reason']];
        }

        $scheduled = ScheduledAction::create([
            'lead_id' => $lead->id,
            'rule_name' => $action['rule_name'] ?? 'حواجز الأتمتة',
            // Marked, so "nothing reached them" can tell a message held for the night from one never sent.
            'action' => $action + ['deferred' => true],
            'run_at' => $verdict['until'],
            'status' => ScheduledAction::PENDING,
        ]);
        RunScheduledAction::dispatch($scheduled->id)->delay($verdict['until']);

        return ['ok' => true, 'result' => 'waiting', 'description' => $verdict['reason']];
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

        /*
         * Kept as an adjustment beside the facts, and the score recomputed.
         * Writing the score directly and then re-scoring — which this did —
         * threw the adjustment away in the same breath: every "+20 on reply"
         * rule reported success and moved nothing.
         */
        $before = (int) $lead->score;
        $lead->score_adjustment = max(-100, min(100, (int) $lead->score_adjustment + $by));
        $this->scoring->apply($lead);
        $lead->save();
        $after = (int) $lead->score;

        return ['ok' => true, 'description' => "التقييم {$before} ← {$after}.", 'before' => (string) $before, 'after' => (string) $after];
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
        if (blank($lead->phone_normalized) && blank($lead->whatsapp)) {
            return ['ok' => true, 'result' => 'skipped', 'description' => 'لا رقم لهذا العميل — تُجووزت الخطوة.'];
        }

        $template = $this->template($lead, 'whatsapp', $action);

        if (is_string($template)) {
            return ['ok' => false, 'description' => $template];
        }

        if (! $template->is_active) {
            return ['ok' => false, 'description' => "الرسالة '{$template->name}' موقوفة — لم تُرسل."];
        }

        /*
         * No actor. A rule is not a person, and attributing the message to the
         * lead's owner would put their name on something they did not write —
         * on the screen and in the audit trail both.
         *
         * `sendSaved` rather than `send`: a rule fires on a lead that has very
         * often never written to us, which is exactly the case where free text
         * is refused and only the approved template will do. Choosing between
         * the two is the sender's job and not a rule's, because the answer
         * depends on the clock and a rule is written once.
         */
        try {
            $message = $this->whatsapp->sendSaved($lead, $template);
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
        $this->reached($lead, $message);

        return ['ok' => true, 'description' => "أُرسلت رسالة واتساب '{$template->name}'."];
    }

    /**
     * The email twin of `sendWhatsapp`, and held to the same rules: the message
     * is a saved template, a refusal is reported rather than thrown, and the
     * sender — not the rule — decides whether this lead may be written to.
     */
    private function sendEmail(Lead $lead, array $action): array
    {
        // Not a failure to report: there is nobody to write to on this channel.
        if (blank($lead->email)) {
            return ['ok' => true, 'result' => 'skipped', 'description' => 'لا بريد لهذا العميل — تُجووزت الخطوة.'];
        }

        $template = $this->template($lead, 'email', $action);

        if (is_string($template)) {
            return ['ok' => false, 'description' => $template];
        }

        if (! $template->is_active) {
            return ['ok' => false, 'description' => "القالب '{$template->name}' موقوف — لم يُرسل."];
        }

        try {
            $message = $this->email->sendSaved($lead, $template);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'description' => 'لم يُرسل البريد: '.$e->getMessage()];
        }

        if ($message->status === MessageStatus::Failed) {
            return ['ok' => false, 'description' => "لم يُرسل البريد '{$template->name}': ".($message->failure_reason ?? 'رفضه المزوّد.')];
        }

        $this->reached($lead, $message);

        return ['ok' => true, 'description' => "أُرسل بريد '{$template->name}'."];
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
        $channel = ($action['channel'] ?? 'whatsapp') === 'email' ? 'email' : 'whatsapp';

        if (! ($action['send'] ?? true)) {
            // Only the link, for a rep to hand over: chosen and shared, not sent.
            ['file' => $file, 'reason' => $reason] = $this->chooser->pick($lead, $kind);

            if (! $file) {
                return ['ok' => false, 'description' => $this->noFile($reason)];
            }

            $this->sharer->forLead($file, $lead, null, 'automation');

            return ['ok' => true, 'description' => "جُهِّز رابط '{$file->name}' للعميل."];
        }

        try {
            $result = $this->delivery->deliver($lead, $channel, null, $kind, null, 'automation');
        } catch (RuntimeException $e) {
            // The share stands (see FileDelivery). When there was nothing to
            // choose the message is a key, not a sentence.
            return ['ok' => false, 'description' => in_array($e->getMessage(), ['no_files', 'no_files_of_kind', 'no_files_for_this_lead'], true)
                ? $this->noFile($e->getMessage())
                : 'جُهِّز الرابط ولم يُرسل: '.$e->getMessage()];
        }

        $where = $channel === 'email' ? 'بالبريد' : 'على واتساب';
        $why = $result['reasons'] !== [] ? ' ('.implode('، ', array_map(fn ($r) => $this->reasonWords($r), $result['reasons'])).')' : '';

        return ['ok' => true, 'description' => "أُرسل '{$result['file']->name}' {$where}{$why}."];
    }

    private function noFile(?string $reason): string
    {
        return match ($reason) {
            'no_files_of_kind' => 'لا يوجد ملف من هذا النوع لمشروع العميل.',
            'no_files_for_this_lead' => 'ملفات المشروع كلها موجَّهة لحملة أو مرحلة أو فئة لا ينتمي إليها هذا العميل.',
            default => 'لا توجد ملفات لمشروع هذا العميل — لم يُرسل شيء.',
        };
    }

    private function reasonWords(string $reason): string
    {
        return match (true) {
            $reason === 'campaign' => 'مخصص لحملته',
            $reason === 'investment_purpose' => 'يشتري للاستثمار',
            $reason === 'needs_financing' => 'يحتاج تمويلًا',
            $reason === 'audience_end_user' => 'يشتري للسكن',
            str_starts_with($reason, 'stage_') => 'مناسب لمرحلته',
            str_starts_with($reason, 'language_') => 'بلغته',
            $reason === 'asked_for' => 'النوع المطلوب',
            default => 'الملف الافتراضي',
        };
    }

    /**
     * The template an action means: the one it names, or — for a journey,
     * which names a purpose — the one written for this lead's project,
     * language and stage. A string back is the reason nothing can be sent.
     */
    private function template(Lead $lead, string $channel, array $action): MessageTemplate|string
    {
        $uuid = (string) ($action['template_uuid'] ?? '');

        if ($uuid !== '') {
            $template = MessageTemplate::where('uuid', $uuid)->where('channel', $channel)->first();

            return $template ?? ($channel === 'email'
                ? 'قالب البريد المحدد غير موجود — لم يُرسل شيء.'
                : 'الرسالة المحددة غير موجودة — لم يُرسل شيء.');
        }

        $purpose = (string) ($action['purpose'] ?? '');

        if ($purpose === '') {
            return $channel === 'email' ? 'لم يُحدَّد قالب بريد — لم يُرسل شيء.' : 'لم تُحدَّد رسالة — لم يُرسل شيء.';
        }

        return $this->picker->pick($lead, $channel, $purpose)
            ?? 'لا توجد رسالة '.$this->purposeWords($purpose).($channel === 'email' ? ' بالبريد' : ' على واتساب').' لمشروع هذا العميل — لم يُرسل شيء.';
    }

    private function purposeWords(string $purpose): string
    {
        return match ($purpose) {
            'welcome' => 'ترحيب',
            'qualification' => 'أسئلة تأهيل',
            'reminder' => 'تذكير',
            'nurture' => 'متابعة',
            'appointment' => 'موعد',
            default => "'{$purpose}'",
        };
    }

    /**
     * A message really reached the lead, so automation is looking after them.
     *
     * The mock never counts: in development it "sends" to show the product,
     * and a lead marked as being looked after by a message nobody received is
     * a lead hidden from every rep.
     */
    private function reached(Lead $lead, ?LeadMessage $message): void
    {
        if ($message === null || $message->provider === 'mock' || $message->status === MessageStatus::Failed) {
            return;
        }

        if ($lead->automation_state === null) {
            $lead->forceFill(['automation_state' => Lead::AUTOMATION_NURTURING])->saveQuietly();
        }
    }

    /**
     * Asks the project's own qualification questions, numbered, in one message.
     *
     * The questions are the ones the project defines — a villa project asks
     * about plot size, an apartment project about floors — and only the ones
     * this lead has not answered yet, so a reminder never asks twice.
     */
    private function askQualification(Lead $lead, array $action): array
    {
        $template = $this->picker->pick($lead, 'whatsapp', 'qualification');

        if (! $template) {
            return ['ok' => false, 'description' => 'لا توجد رسالة أسئلة تأهيل — لم يُرسل شيء.'];
        }

        // Whatever the lead already told us — on the form, or in the conversation — answers its
        // question; nobody is asked for something they have said.
        $completer = app(QualificationCompleter::class);
        $completer->syncAnswers($lead);
        $asking = $completer->unanswered($lead);

        if ($asking->isEmpty() || blank(app(TemplateRenderer::class)->values($lead)['questions'] ?? null)) {
            return ['ok' => true, 'result' => 'skipped', 'description' => 'كل أسئلة التأهيل مُجابة — لم يُرسل شيء.'];
        }

        try {
            $message = $this->whatsapp->sendSaved($lead, $template);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'description' => 'لم تُرسل أسئلة التأهيل: '.$e->getMessage()];
        }

        // Remembered, so the reply can be read as the answers to exactly these questions, in this order.
        if ($message->exists) {
            $message->forceFill(['context' => ['questions' => $asking->pluck('id')->all()]])->saveQuietly();
        }

        $this->reached($lead, $message);
        $this->activities->log($lead, ActivityType::NoteAdded, null, 'أُرسلت أسئلة التأهيل تلقائيًا.', $this->signature($action));

        return ['ok' => true, 'description' => 'أُرسلت أسئلة التأهيل على واتساب.'];
    }

    /** Marks the lead a priority — when it is hot, if the rule says so. */
    private function setPriority(Lead $lead, array $action): array
    {
        if (($action['when'] ?? null) === 'hot' && $lead->fresh()?->temperature?->value !== 'hot') {
            return ['ok' => true, 'description' => 'الاهتمام ليس مرتفعًا بعد — لم تتغير الأولوية.'];
        }

        if ($lead->is_priority) {
            return ['ok' => true, 'description' => 'العميل أولوية بالفعل.'];
        }

        $lead->forceFill(['is_priority' => true])->save();
        $this->activities->log($lead, ActivityType::NoteAdded, null, 'أصبح العميل أولوية تلقائيًا.', $this->signature($action));

        return ['ok' => true, 'description' => 'أصبح العميل أولوية.', 'before' => 'عادي', 'after' => 'أولوية'];
    }

    /**
     * A person's turn.
     *
     * The lead leaves the automated journey and joins the rep's queue, with the
     * reason written on it — "replied", "qualified", "no reply after two days",
     * "nothing could reach them" — so the first thing the rep reads is why they
     * are the one holding it now. `when: unreached` hands over only a lead no
     * automated message actually reached; one that was reached stays with the
     * journey.
     */
    private function handOff(Lead $lead, array $action): array
    {
        $reason = (string) ($action['reason'] ?? 'needs_human');
        $lead = $lead->fresh() ?? $lead;

        if (($action['when'] ?? null) === 'unreached' && $lead->automation_state === Lead::AUTOMATION_NURTURING) {
            return ['ok' => true, 'description' => 'وصلت الأتمتة إلى العميل — يستمر المسار.'];
        }

        // Their welcome is held for the quiet hours: it will reach them in the morning, so a person is not needed.
        if (($action['when'] ?? null) === 'unreached' && ScheduledAction::withoutGlobalScopes()->where('lead_id', $lead->id)->where('status', ScheduledAction::PENDING)->get()
            ->contains(fn (ScheduledAction $s) => ! empty($s->action['deferred']) && Recovery::isContact((string) ($s->action['type'] ?? '')))) {
            return ['ok' => true, 'description' => 'رسائل العميل مؤجلة لانتهاء ساعات الهدوء — يستمر المسار.'];
        }

        if ($lead->automation_state === Lead::AUTOMATION_HANDED_OFF && $lead->needs_human_reason === $reason) {
            return ['ok' => true, 'description' => 'العميل مع المندوب بالفعل.'];
        }

        $lead->forceFill([
            'automation_state' => Lead::AUTOMATION_HANDED_OFF,
            'needs_human_reason' => $reason,
            'handed_off_at' => now(),
        ])->save();

        // The messages still waiting would now talk over the rep.
        $frozen = app(Handoff::class)->freeze($lead);

        $words = Lead::handOffWords($reason);
        $this->activities->log($lead, ActivityType::HandedOff, null, "سُلِّم العميل للمندوب: {$words}.", $this->signature($action));

        // Told, except when nothing could reach the lead: that is every lead in
        // a workspace with no channel connected, and the queue already shows it.
        if ($reason !== 'unreached') {
            $this->notify($lead, ['audience' => 'owner', 'message' => "{$lead->full_name}: {$words} — دورك الآن.", 'rule_name' => $action['rule_name'] ?? null]);
        }

        return ['ok' => true, 'description' => "سُلِّم للمندوب ({$words}).".($frozen > 0 ? " جُمِّدت {$frozen} رسائل آلية منتظرة." : ''), 'handed_off' => true, 'after' => $words];
    }

    /** Hot, warm or cold — the classification a rep sorts by. */
    private function setTemperature(Lead $lead, array $action): array
    {
        $to = LeadTemperature::tryFrom((string) ($action['temperature'] ?? ''));

        if (! $to) {
            return ['ok' => false, 'description' => 'لم يُحدَّد التصنيف.'];
        }

        $before = $lead->temperature?->value;
        $lead->forceFill(['temperature' => $to])->save();

        return ['ok' => true, 'description' => "التصنيف {$before} ← {$to->value}.", 'before' => $before, 'after' => $to->value];
    }

    /**
     * Stops every waiting step of every rule for this lead — the customer
     * asked to stop, or a person is taking over and wants quiet.
     */
    private function stopAutomation(Lead $lead, array $action): array
    {
        $pending = ScheduledAction::where('lead_id', $lead->id)->where('status', ScheduledAction::PENDING)->get();

        foreach ($pending as $step) {
            $step->resolve(ScheduledAction::CANCELLED, 'أُوقفت الأتمتة لهذا العميل.');
            app(ActionLog::class)->resolveScheduled($step->id, $step->action ?? [], [], 'أُوقفت الأتمتة لهذا العميل.');
        }

        $lead->forceFill(['automation_state' => Lead::AUTOMATION_HANDED_OFF, 'needs_human_reason' => $lead->needs_human_reason ?? 'needs_human', 'handed_off_at' => $lead->handed_off_at ?? now()])->save();

        return ['ok' => true, 'description' => 'أُوقفت الأتمتة ('.$pending->count().' خطوة منتظرة أُلغيت).', 'after' => 'متوقفة'];
    }

    /**
     * Back to the journey: a cold lead, or one a rep could not reach, gets the
     * gentle nurture message and automation looks after it again.
     */
    private function startNurture(Lead $lead, array $action): array
    {
        $sent = $this->sendWhatsapp($lead, ['purpose' => 'nurture'] + $action);

        if (! $sent['ok']) {
            $sent = $this->sendEmail($lead, ['purpose' => 'nurture'] + $action);
        }

        if ($sent['ok'] && $lead->fresh()?->automation_state !== Lead::AUTOMATION_NURTURING) {
            $lead->forceFill(['automation_state' => Lead::AUTOMATION_NURTURING])->saveQuietly();
        }

        return $sent + ['after' => 'رعاية آلية'];
    }

    /** A call for the owner to make, as a follow-up that says so. */
    private function createCallTask(Lead $lead, array $action): array
    {
        return $this->scheduleFollowUp($lead, [
            'in_hours' => max(1, (int) ($action['in_hours'] ?? 1)),
            'note' => trim((string) ($action['note'] ?? '')) ?: 'اتصال بالعميل',
        ] + $action);
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
