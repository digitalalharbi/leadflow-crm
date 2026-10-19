<?php

namespace App\Domain\Automation;

use App\Enums\ActivityType;
use App\Enums\MessageDirection;
use App\Jobs\RunScheduledAction;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadMessage;
use App\Models\ScheduledAction;

/**
 * Who has the lead: a rule, or a person.
 *
 * The moment a rep calls, writes or emails a lead, they have it. Messages still
 * queued from the journey ("here is the brochure", "are you still interested?")
 * would arrive in the middle of their conversation, so they are cancelled and
 * the automated messages stop. The reverse is the rep saying nobody picked up:
 * the journey may carry on, gently.
 */
class HumanTakeover
{
    /** A customer who spoke this recently is in a conversation, not unreachable. */
    private const RECENTLY_SPOKE_DAYS = 7;

    /** How long after "no answer" the first resumed message may go. */
    private const RESUME_AFTER_HOURS = 24;

    /** A person made contact. Returns how many waiting steps were cancelled, or null when nothing changed. */
    public function tookOver(Lead $lead): ?int
    {
        $lead = $lead->fresh() ?? $lead;

        if ($lead->status?->isClosed() || $lead->automation_state === Lead::AUTOMATION_HANDED_OFF) {
            return null;
        }

        $pending = ScheduledAction::withoutGlobalScopes()->where('lead_id', $lead->id)->where('status', ScheduledAction::PENDING)->get();

        if ($lead->automation_state !== Lead::AUTOMATION_NURTURING && $pending->isEmpty()) {
            return null;
        }

        foreach ($pending as $step) {
            $step->resolve(ScheduledAction::CANCELLED, 'استلم المندوب العميل — أُلغيت الخطوة الآلية.');
            app(ActionLog::class)->resolveScheduled($step->id, $step->action ?? [], [], 'استلم المندوب العميل — أُلغيت الخطوة الآلية.');
        }

        $lead->forceFill([
            'automation_state' => Lead::AUTOMATION_HANDED_OFF,
            'handed_off_at' => $lead->handed_off_at ?? now(),
        ])->saveQuietly();

        LeadActivity::create([
            'lead_id' => $lead->id,
            'type' => ActivityType::HandedOff,
            'description' => 'استلم المندوب العميل — توقفت الرسائل الآلية'.($pending->isNotEmpty() ? " ({$pending->count()} خطوة أُلغيت)." : '.'),
            'meta' => ['by' => 'human', 'cancelled' => $pending->count()],
        ]);

        return $pending->count();
    }

    /** A person marked "no answer": the journey may continue, after a day. */
    public function noResponse(Lead $lead): bool
    {
        $lead = $lead->fresh() ?? $lead;

        if ($lead->status?->isClosed() || $lead->automation_state !== Lead::AUTOMATION_HANDED_OFF) {
            return false;
        }

        $spoke = LeadMessage::withoutGlobalScopes()
            ->where('lead_id', $lead->id)
            ->where('direction', MessageDirection::Inbound->value)
            ->where('created_at', '>=', now()->subDays(self::RECENTLY_SPOKE_DAYS))
            ->exists();

        if ($spoke || $lead->whatsapp_opt_out_at !== null && $lead->email_opt_out_at !== null) {
            return false;
        }

        $lead->forceFill([
            'automation_state' => Lead::AUTOMATION_NURTURING,
            'needs_human_reason' => null,
            'handed_off_at' => null,
        ])->saveQuietly();

        $runAt = now()->addHours(self::RESUME_AFTER_HOURS);
        $step = (new ScheduledAction)->forceFill([
            'tenant_id' => $lead->tenant_id,
            'lead_id' => $lead->id,
            'rule_name' => 'استئناف الأتمتة',
            'action' => ['type' => 'start_nurture', 'rule_name' => 'استئناف الأتمتة'],
            'run_at' => $runAt,
            'status' => ScheduledAction::PENDING,
        ]);
        $step->save();
        RunScheduledAction::dispatch($step->id)->delay($runAt);

        LeadActivity::create([
            'lead_id' => $lead->id,
            'type' => ActivityType::HandedOff,
            'description' => 'لم يرد العميل — استُؤنفت الأتمتة (رسالة رعاية بعد 24 ساعة).',
            'meta' => ['by' => 'human', 'resumed' => true],
        ]);

        return true;
    }
}
