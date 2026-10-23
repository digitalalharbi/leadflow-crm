<?php

namespace App\Domain\Automation;

use App\Domain\Leads\InterestEngine;
use App\Events\Contracts\LeadDomainEvent;
use App\Jobs\RunScheduledAction;
use App\Models\Appointment;
use App\Models\AutomationActionLog;
use App\Models\FileShare;
use App\Models\Lead;
use App\Models\LeadEngagement;
use App\Models\LeadMessage;
use App\Models\Reservation;
use App\Models\ScheduledAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * For every lead, the next week laid out: now, +15 minutes, +4 hours, +24 hours,
 * +3 days, +7 days — what happens at each, on which channel, and why.
 *
 * It is not a template stamped on everybody. The ladder bends to what is known:
 *  - the project's cadence stretches or squeezes it (gentle ×2, intensive ×½);
 *  - a customer who has answered is not messaged any more — the plan becomes
 *    the rep's, as calls that fall away the moment somebody calls;
 *  - one who opened the brochure or the email is nudged sooner and on the
 *    channel they engaged with, one who has not is given email as a second door;
 *  - a channel that cannot be used (no number, no address, opted out) is not
 *    planned, and the step says so;
 *  - quiet hours move a step to the end of the quiet window;
 *  - what the journey rule already scheduled is not scheduled twice.
 *

 * When a lead turns interested the week is laid out again, differently: the general nurture stops and the
 * interested journey takes its place — the owner reminded first, the customer a different message at each
 * step (never the same words twice), a visit offered, a last word — on the same six rungs. It ends the
 * moment a visit is booked, a unit is held, the deal is won or lost, or the customer unsubscribes, and a
 * person who has the conversation keeps only the steps that are theirs.
 *
 * Planning writes the plan to the lead and schedules its steps as ordinary
 * scheduled actions — so guardrails, project pauses and takeover cancellation
 * all apply to them exactly as to everything else.
 */
class FollowUpPlanner
{
    /** The ladder, in minutes. */
    public const LADDER = ['now' => 0, '15m' => 15, '4h' => 240, '24h' => 1440, '3d' => 4320, '7d' => 10080];

    /** Where a plan stands. A plan a person paused or stopped is never re-planned behind their back. */
    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    public const STOPPED = 'stopped';

    public const COMPLETED = 'completed';

    public function __construct(private Guardrails $guardrails) {}

    /** The event handler: plan a new lead, re-plan one that has answered, and settle one that is decided. */
    public function onEvent(LeadDomainEvent $event): void
    {
        $lead = $event->lead()->fresh();

        if (! $lead) {
            return;
        }

        if ($lead->status?->isClosed()) {
            $this->stop($lead, $lead->status->value);

            return;
        }

        match ($event->name()) {
            'lead.created' => $this->apply($lead),
            // A person who paused or stopped the plan decided; an answer does not undo that.
            'lead.replied' => $this->held($lead) ? null : $this->apply($lead, replace: true),
            'lead.interest_changed' => $this->interestChanged($lead),
            default => null,
        };
    }

    /** Somebody booked a visit: the follow-up has done its job, and the visit's own reminders stay. */
    public function appointmentBooked(Lead $lead): void
    {
        $this->stop($lead, 'appointment');
    }

    /** A unit is held for them: the interested journey has done its job. */
    public function reservationMade(Lead $lead): void
    {
        $this->stop($lead, 'reservation');
    }

    /**
     * The customer crossed into "interested" (or back out of it). Into it: whatever general nurture was
     * waiting is cancelled, the lead becomes a priority, and the journey for that moment is laid out.
     * Out of it (they went cold): the general plan comes back. Already in it — interested to hot —
     * nothing is rebuilt. A plan a person paused or stopped is never restarted behind their back.
     */
    private function interestChanged(Lead $lead): void
    {
        if ($this->held($lead)) {
            return;
        }

        $interested = $this->isInterested($lead);
        $inJourney = ($lead->follow_up_plan['mode'] ?? null) === 'interested';

        if ($interested && ! $inJourney) {
            $this->cancelAll($lead, 'أصبح العميل مهتمًا — انتقل من الرعاية العامة إلى رحلة الاهتمام.');
            // First in the rep's queue: the same priority a rule would set, written by the same code.
            app(ActionExecutor::class)->execute($lead, ['type' => 'set_priority', 'rule_name' => 'رحلة الاهتمام']);
            $this->apply($lead->fresh() ?? $lead);
        } elseif (! $interested && $inJourney) {
            $this->cancelAll($lead, 'لم يعد العميل مهتمًا — عادت الرعاية العامة.');
            $this->apply($lead->fresh() ?? $lead);
        }
    }

    private function isInterested(Lead $lead): bool
    {
        return ! $lead->status?->isClosed() && in_array($lead->interest_level, InterestEngine::INTERESTED, true);
    }

    /**
     * Opt-out or a bounce changed who can be reached. Nobody left to write to ends the plan; a
     * channel gone re-lays the rest on the one that remains.
     */
    public function contactChanged(Lead $lead): void
    {
        if ($lead->status?->isClosed() || $this->held($lead)) {
            return;
        }

        if ($this->unsubscribed($lead)) {
            $this->stop($lead, 'unsubscribed');

            return;
        }

        if (is_array($lead->follow_up_plan)) {
            $this->apply($lead, replace: true);
        }
    }

    /** Called when a plan step has run: look at the situation again, and stop if it no longer calls for the rest. */
    public function afterStep(Lead $lead): void
    {
        $lead = $lead->fresh() ?? $lead;

        [$decision, $reason] = $this->evaluate($lead);

        if ($decision === 'stop' && ! $this->held($lead) && is_array($lead->follow_up_plan) && ($lead->follow_up_plan['status'] ?? self::ACTIVE) === self::ACTIVE) {
            $this->stop($lead, $reason);
        }
    }

    /**
     * Why a plan step should not run now, or null when it may. Checked at the moment it is due,
     * because everything the plan decided was true when it decided it.
     */
    public function gate(Lead $lead, ScheduledAction $step): ?string
    {
        if (empty($step->action['plan'])) {
            return null;
        }

        $status = ($lead->follow_up_plan['status'] ?? self::ACTIVE);

        if (in_array($status, [self::PAUSED, self::STOPPED], true)) {
            return 'أوقف أحدهم خطة المتابعة.';
        }

        if ($this->hasAppointment($lead)) {
            return 'حُجز موعد — لم تعد المتابعة لازمة.';
        }

        if ($this->hasReservation($lead)) {
            return 'حُجزت وحدة — لم تعد المتابعة لازمة.';
        }

        if ($this->unsubscribed($lead)) {
            return 'ألغى العميل الاشتراك.';
        }

        return null;
    }

    /**
     * The plan, without writing anything.
     *
     * @return array{status:string, mode:string, generated_at:string, steps:list<array<string,mixed>>}
     */
    public function plan(Lead $lead): array
    {
        $now = now();
        $replied = $lead->first_response_at !== null || $this->hasInbound($lead);
        $human = $replied || $lead->automation_state === Lead::AUTOMATION_HANDED_OFF;
        $factor = ProjectSettings::cadenceFactor($lead);
        $channels = ['whatsapp' => $lead->acceptsWhatsapp() && (filled($lead->phone_normalized) || filled($lead->phone)), 'email' => $lead->acceptsEmail()];
        $engaged = LeadEngagement::where('lead_id', $lead->id)->where('happened_at', '>=', $now->copy()->subDays(30))->exists();
        $sent = $this->sentSomething($lead);
        $paused = ! ProjectSettings::isActive($lead);
        $interested = $this->isInterested($lead);

        $steps = [];

        foreach (self::LADDER as $key => $minutes) {
            $at = $now->copy()->addMinutes($key === 'now' ? 0 : (int) round($minutes * $factor));
            [$action, $channel, $why] = match (true) {
                $interested => $this->interestedStep($key, $channels, $human, $lead),
                $human => $this->humanStep($key),
                default => $this->automatedStep($key, $channels, $engaged, $sent),
            };

            $state = 'planned';

            if ($paused && $channel !== 'call' && $channel !== 'team') {
                [$state, $why] = ['skipped', 'project_paused'];
            } elseif ($action === null) {
                [$state, $why] = ['skipped', $why];
            } elseif ($key === 'now' && ! $human && ! $interested && $sent) {
                [$state, $why] = ['done', 'already_sent'];
            }

            // A message waits out the quiet hours; a call task is the rep's to schedule.
            if ($state === 'planned' && $channel !== 'call' && $channel !== 'team' && ($until = $this->guardrails->quietUntil($lead, $at))) {
                $at = $until;
                $why = $why.'+quiet_hours';
            }

            $steps[] = [
                'key' => $key,
                'at' => $at->toIso8601String(),
                'channel' => $channel,
                'action' => $action,
                'state' => $state,
                'why' => $why,
                'scheduled_action_id' => null,
            ];
        }

        return ['status' => self::ACTIVE, 'mode' => $interested ? 'interested' : ($human ? 'human' : 'automated'), 'generated_at' => $now->toIso8601String(), 'steps' => $steps];
    }

    /**
     * Lay the plan out and schedule it. `replace` first cancels the steps an
     * earlier plan left waiting (the situation changed: the customer answered).
     *
     * @return array<string,mixed>
     */
    public function apply(Lead $lead, bool $replace = false): array
    {
        if ($replace) {
            $this->cancelPlanned($lead, 'أُعيد تخطيط المتابعة.');
        }

        $plan = $this->plan($lead);
        $existing = ScheduledAction::withoutGlobalScopes()->where('lead_id', $lead->id)->where('status', ScheduledAction::PENDING)->get();

        foreach ($plan['steps'] as &$step) {
            if ($step['state'] !== 'planned' || $step['action'] === null) {
                continue;
            }

            $at = Carbon::parse($step['at']);

            // The journey already has this kind of step waiting — the same message, or the same
            // hand-off: link to it rather than writing to the customer twice for one purpose.
            $same = $existing->first(fn (ScheduledAction $s) => empty($s->action['plan']) && $this->signature($s->action) === $this->signature($step['action']));

            if ($same) {
                $step['scheduled_action_id'] = $same->id;
                $step['why'] = 'journey_covers';

                continue;
            }

            $row = (new ScheduledAction)->forceFill([
                'tenant_id' => $lead->tenant_id,
                'lead_id' => $lead->id,
                'rule_name' => 'خطة المتابعة',
                'action' => $step['action'] + ['plan' => true, 'rule_name' => 'خطة المتابعة'],
                'run_at' => $at,
                'status' => ScheduledAction::PENDING,
            ]);
            $row->save();

            $this->queue($row);
            $step['scheduled_action_id'] = $row->id;
        }
        unset($step);

        $lead->forceFill(['follow_up_plan' => $plan, 'follow_up_planned_at' => now()])->saveQuietly();

        return $plan;
    }

    /**
     * What a lead's plan looks like now, for the workspace: each step's state read from what
     * actually happened to it, the next step with its reason and channel, how far along it is,
     * and whether the plan continues, escalates to a person or has stopped — and why.
     */
    public function view(Lead $lead): ?array
    {
        $plan = $lead->follow_up_plan;

        if (! is_array($plan) || empty($plan['steps'])) {
            return null;
        }

        $ids = array_filter(array_column($plan['steps'], 'scheduled_action_id'));
        $rows = ScheduledAction::withoutGlobalScopes()->whereIn('id', $ids)->get()->keyBy('id');
        $recovered = AutomationActionLog::withoutGlobalScopes()->whereIn('scheduled_action_id', $ids)->where('result', AutomationActionLog::RECOVERED)->pluck('scheduled_action_id')->all();

        $plan['steps'] = array_map(function (array $step) use ($rows, $recovered, $lead) {
            $row = $step['scheduled_action_id'] ? $rows->get($step['scheduled_action_id']) : null;
            $step['fallback'] = false;

            if ($row) {
                $step['state'] = match ($row->status) {
                    ScheduledAction::DONE => 'done',
                    ScheduledAction::CANCELLED => 'cancelled',
                    ScheduledAction::FAILED => 'failed',
                    default => 'planned',
                };
                $step['at'] = $row->run_at->toIso8601String();

                if (isset($row->action['moved_to'])) {
                    $step['why'] = 'rescheduled';
                }

                // A message due in the quiet hours goes at the end of them, whoever scheduled it.
                if ($step['state'] === 'planned' && Recovery::isContact((string) ($row->action['type'] ?? '')) && ($until = $this->guardrails->quietUntil($lead, $row->run_at))) {
                    $step['at'] = $until->toIso8601String();
                    $step['why'] = str_contains($step['why'], 'quiet_hours') ? $step['why'] : $step['why'].'+quiet_hours';
                }

                // The WhatsApp could not go, so the same message went by email.
                if (in_array($row->id, $recovered, true)) {
                    $step['fallback'] = true;
                    $step['channel'] = $step['channel'] === 'whatsapp' ? 'email' : 'whatsapp';
                }
            }

            return $step;
        }, $plan['steps']);

        [$decision, $reason] = $this->evaluate($lead);
        $stored = $plan['status'] ?? self::ACTIVE;
        $upcoming = collect($plan['steps'])->where('state', 'planned')->sortBy('at')->values();
        $counted = collect($plan['steps'])->where('state', '!=', 'skipped');

        $status = match (true) {
            $stored === self::PAUSED => self::PAUSED,
            $stored === self::STOPPED || $decision === 'stop' => self::STOPPED,
            $upcoming->isEmpty() => self::COMPLETED,
            default => self::ACTIVE,
        };

        $plan['status'] = $status;
        $plan['decision'] = $status === self::PAUSED ? 'stop' : $decision;
        $plan['reason'] = $status === self::PAUSED ? 'paused_by_user' : ($status === self::STOPPED && ! empty($plan['stopped_reason']) && $decision !== 'stop' ? $plan['stopped_reason'] : $reason);
        $plan['next'] = in_array($status, [self::ACTIVE], true) ? $upcoming->first() : null;
        $plan['progress'] = ['done' => $counted->where('state', 'done')->count(), 'total' => $counted->count()];
        $plan['can'] = [
            'pause' => $status === self::ACTIVE,
            'resume' => in_array($status, [self::PAUSED, self::STOPPED], true) && ! $lead->status?->isClosed() && ! $this->unsubscribed($lead),
            'stop' => in_array($status, [self::ACTIVE, self::PAUSED], true),
            'reschedule' => $status === self::ACTIVE && $this->waiting($lead)->isNotEmpty(),
        ];

        return $plan;
    }

    /**
     * Look at the lead again and say what the plan should do: carry on, hand the next move to a
     * person, or stop. Read from live facts, never from what the plan remembers.
     *
     * @return array{0:'continue'|'escalate'|'stop', 1:string}
     */
    public function evaluate(Lead $lead): array
    {
        return match (true) {
            $lead->status?->isClosed() => ['stop', $lead->status->value],
            $this->hasAppointment($lead) => ['stop', 'appointment'],
            $this->hasReservation($lead) => ['stop', 'reservation'],
            $this->unsubscribed($lead) => ['stop', 'unsubscribed'],
            ($lead->follow_up_plan['status'] ?? self::ACTIVE) === self::STOPPED => ['stop', 'stopped_by_user'],
            ($lead->follow_up_plan['status'] ?? self::ACTIVE) === self::PAUSED => ['stop', 'paused_by_user'],
            ! $this->reachable($lead) => ['escalate', 'invalid_contact'],
            $lead->first_response_at !== null || $this->hasInbound($lead) => ['escalate', 'replied'],
            $lead->automation_state === Lead::AUTOMATION_HANDED_OFF => ['escalate', 'human_takeover'],
            $this->exhausted($lead) => ['escalate', 'automation_exhausted'],
            default => ['continue', 'no_reply'],
        };
    }

    // ------------------------------------------------------------------ the buttons

    /** Hold everything automatic for this lead until somebody resumes it. */
    public function pause(Lead $lead, string $by = ''): array
    {
        $this->cancelAll($lead, 'أوقف'.($by !== '' ? " {$by}" : '').' المتابعة مؤقتًا.');

        return $this->mark($lead, self::PAUSED, ['paused_at' => now()->toIso8601String()]);
    }

    /** Lay the plan out again from now. A lead that is decided, booked or unsubscribed is not resumed. */
    public function resume(Lead $lead): array
    {
        if ($lead->status?->isClosed() || $this->unsubscribed($lead) || $this->hasAppointment($lead) || $this->hasReservation($lead)) {
            return $this->view($lead) ?? [];
        }

        $plan = $this->apply($lead, replace: true);

        return $this->view($lead->fresh()) ?? $plan;
    }

    /** End the follow-up. Whatever was waiting is cancelled; the visit's own reminders stay. */
    public function stop(Lead $lead, string $reason = 'stopped_by_user', string $by = ''): array
    {
        $this->cancelAll($lead, $reason === 'stopped_by_user' ? 'أوقف'.($by !== '' ? " {$by}" : '').' المتابعة.' : $this->stopWords($reason));

        return $this->mark($lead, self::STOPPED, ['stopped_at' => now()->toIso8601String(), 'stopped_reason' => $reason]);
    }

    /**
     * Move the waiting steps to a new time, keeping the gaps between them. The journey's own
     * waiting steps move with the plan's: from the customer's side it is one follow-up.
     *
     * @throws \DomainException when nothing is waiting to be moved
     */
    public function reschedule(Lead $lead, Carbon $to): array
    {
        $pending = $this->waiting($lead);

        if ($pending->isEmpty()) {
            throw new \DomainException('no_pending_steps');
        }

        $delta = (int) $pending->first()->run_at->diffInSeconds($to, false);

        foreach ($pending as $step) {
            $at = $step->run_at->copy()->addSeconds($delta);

            // Moved in place, so the trace keeps one row per step. The old timer will go off at the
            // old time and find `moved_to` — it does nothing, and the new one runs the step.
            $step->forceFill(['run_at' => $at, 'action' => $step->action + ['moved_to' => $at->toIso8601String()]])->saveQuietly();
            $this->queue($step);
        }

        return $this->view($lead->fresh()) ?? [];
    }

    /** Cancel what an earlier plan left waiting. */
    private function cancelPlanned(Lead $lead, string $why): void
    {
        ScheduledAction::withoutGlobalScopes()->where('lead_id', $lead->id)->where('status', ScheduledAction::PENDING)->get()
            ->filter(fn (ScheduledAction $s) => ! empty($s->action['plan']))
            ->each(function (ScheduledAction $s) use ($why) {
                $s->resolve(ScheduledAction::CANCELLED, $why);
            });
    }

    /**
     * What an unanswered lead gets at each moment.
     *
     * @param  array{whatsapp:bool,email:bool}  $channels
     * @return array{0:?array<string,mixed>, 1:string, 2:string} action, channel, why
     */
    private function automatedStep(string $key, array $channels, bool $engaged, bool $sent): array
    {
        $wa = $channels['whatsapp'];
        $mail = $channels['email'];
        $message = fn (string $channel, string $purpose) => ['type' => $channel === 'email' ? 'send_email' : 'send_whatsapp', 'purpose' => $purpose, 'unless' => 'replied'];

        return match ($key) {
            'now' => $wa ? [['type' => 'send_whatsapp', 'purpose' => 'welcome'], 'whatsapp', 'welcome'] : ($mail ? [['type' => 'send_email', 'purpose' => 'welcome'], 'email', 'no_whatsapp'] : [null, 'whatsapp', 'no_channel']),
            '15m' => $wa ? [$message('whatsapp', 'reminder'), 'whatsapp', 'no_reply'] : ($mail ? [$message('email', 'reminder'), 'email', 'no_whatsapp'] : [null, 'whatsapp', 'no_channel']),
            // Engaged with what we sent: nudge on that same door, sooner. Silent: open the other door.
            '4h' => $engaged && $wa ? [$message('whatsapp', 'reminder'), 'whatsapp', 'engaged']
                : ($mail ? [$message('email', 'nurture'), 'email', $engaged ? 'engaged' : 'second_door'] : ($wa ? [$message('whatsapp', 'nurture'), 'whatsapp', 'no_email'] : [null, 'email', 'no_channel'])),
            '24h' => $wa ? [$message('whatsapp', 'nurture'), 'whatsapp', 'no_reply'] : ($mail ? [$message('email', 'nurture'), 'email', 'no_whatsapp'] : [null, 'whatsapp', 'no_channel']),
            // A person's voice: automation has had its chances.
            '3d' => [['type' => 'create_call_task', 'in_hours' => 1, 'note' => 'ثلاثة أيام بلا رد: اتصال مباشر.', 'unless' => 'contacted'], 'call', 'no_reply'],
            '7d' => [['type' => 'hand_off', 'reason' => 'no_reply', 'unless' => 'replied'], 'team', 'no_reply'],
        };
    }

    /**
     * What an interested lead gets: the owner first, then the customer — a different message each time —
     * and a visit offered. With a person on the conversation only the rep's steps remain.
     *
     * @param  array{whatsapp:bool,email:bool}  $channels
     * @return array{0:?array<string,mixed>, 1:string, 2:string} action, channel, why
     */
    private function interestedStep(string $key, array $channels, bool $human, Lead $lead): array
    {
        $wa = $channels['whatsapp'];
        $mail = $channels['email'];
        $customer = fn (string $purpose, string $why) => $wa
            ? [['type' => 'send_whatsapp', 'purpose' => $purpose, 'unless' => 'replied'], 'whatsapp', $why]
            : ($mail ? [['type' => 'send_email', 'purpose' => $purpose, 'unless' => 'replied'], 'email', 'no_whatsapp'] : [null, 'whatsapp', 'no_channel']);
        $call = fn (string $note) => ['type' => 'create_call_task', 'in_hours' => 1, 'note' => $note, 'unless' => 'contacted'];

        return match ($key) {
            'now' => $human ? [null, 'team', 'human_has_it'] : $customer('interested', 'interested'),
            // The owner is reminded whether or not automation has the customer: somebody should have called.
            '15m' => [['type' => 'notify', 'audience' => 'owner', 'message' => 'عميل مهتم ينتظر اتصالك — ابدأ به الآن.', 'unless' => 'contacted'], 'team', 'interested'],
            '4h' => match (true) {
                $human => [null, 'call', 'human_has_it'],
                $this->brochureShared($lead) => [null, 'whatsapp', 'already_sent'],
                default => [['type' => 'share_file', 'kind' => '', 'send' => true, 'channel' => $wa ? 'whatsapp' : 'email', 'unless' => 'replied'], $wa ? 'whatsapp' : 'email', 'brochure'],
            },
            '24h' => $human ? [$call('متابعة اهتمام العميل.'), 'call', 'interested'] : $customer('interested_follow_up', 'interested'),
            '3d' => $human ? [$call('اعرض على العميل زيارة للمشروع.'), 'call', 'visit_cta'] : $customer('visit_invite', 'visit_cta'),
            '7d' => $human
                ? [['type' => 'notify', 'audience' => 'managers', 'message' => 'عميل مهتم منذ أسبوع ولم تُحسم حالته.', 'unless' => 'contacted'], 'team', 'interested']
                : $customer('final', 'final'),
        };
    }

    private function brochureShared(Lead $lead): bool
    {
        return FileShare::withoutGlobalScopes()->where('lead_id', $lead->id)->exists();
    }

    /** What a lead who has answered gets: the rep's own steps, each falling away once somebody has called. */
    private function humanStep(string $key): array
    {
        $call = fn (string $note) => ['type' => 'create_call_task', 'in_hours' => 1, 'note' => $note, 'unless' => 'contacted'];

        return match ($key) {
            'now' => [null, 'call', 'replied'],
            '15m' => [null, 'call', 'replied'],
            '4h' => [$call('ردّ العميل ولم يُتصل به بعد.'), 'call', 'replied'],
            '24h' => [$call('متابعة اليوم الأول بعد الرد.'), 'call', 'replied'],
            '3d' => [$call('متابعة اليوم الثالث.'), 'call', 'replied'],
            '7d' => [['type' => 'notify', 'audience' => 'managers', 'message' => 'عميل ردّ منذ أسبوع ولم تُحسم حالته.', 'unless' => 'contacted'], 'team', 'replied'],
        };
    }

    private function mark(Lead $lead, string $status, array $extra = []): array
    {
        $plan = is_array($lead->follow_up_plan) && ! empty($lead->follow_up_plan['steps']) ? $lead->follow_up_plan : $this->plan($lead);
        $plan = array_merge($plan, $extra, ['status' => $status]);
        $lead->forceFill(['follow_up_plan' => $plan, 'follow_up_planned_at' => $lead->follow_up_planned_at ?? now()])->saveQuietly();

        return $this->view($lead->fresh() ?? $lead) ?? $plan;
    }

    /** Whether a person has paused or stopped this lead's plan. */
    private function held(Lead $lead): bool
    {
        return in_array($lead->follow_up_plan['status'] ?? self::ACTIVE, [self::PAUSED, self::STOPPED], true);
    }

    /**
     * What is waiting for this lead and automatic, earliest first — everything but a visit's own messages.
     *
     * @return Collection<int,ScheduledAction>
     */
    private function waiting(Lead $lead)
    {
        return ScheduledAction::withoutGlobalScopes()->where('lead_id', $lead->id)->where('status', ScheduledAction::PENDING)->orderBy('run_at')->get()
            ->reject(fn (ScheduledAction $s) => ($s->action['purpose'] ?? null) === 'appointment' || ($s->action['type'] ?? '') === 'request_appointment')->values();
    }

    /** Everything automatic that is waiting for this lead, except the visit's own messages. */
    private function cancelAll(Lead $lead, string $why): int
    {
        $waiting = $this->waiting($lead);

        $waiting->each(function (ScheduledAction $s) use ($why) {
            $s->resolve(ScheduledAction::CANCELLED, $why);
            app(ActionLog::class)->resolveScheduled($s->id, $s->action ?? [], [], $why);
        });

        return $waiting->count();
    }

    private function stopWords(string $reason): string
    {
        return match ($reason) {
            'appointment' => 'حُجز موعد — انتهت المتابعة الآلية.',
            'reservation' => 'حُجزت وحدة — انتهت المتابعة الآلية.',
            'unsubscribed' => 'ألغى العميل الاشتراك — توقفت المتابعة.',
            'won' => 'تم إغلاق العميل بالفوز — توقفت المتابعة.',
            'lost' => 'خسرنا العميل — توقفت المتابعة.',
            default => 'توقفت المتابعة.',
        };
    }

    /** A delayed job on the sync driver would run at once, which is not what waiting means. */
    private function queue(ScheduledAction $row): void
    {
        if (config('queue.default') === 'sync' && $row->run_at->isFuture()) {
            return;
        }

        RunScheduledAction::dispatch($row->id)->delay($row->run_at);
    }

    private function hasReservation(Lead $lead): bool
    {
        return Reservation::withoutGlobalScopes()->where('lead_id', $lead->id)->whereIn('status', Reservation::OPEN)->exists();
    }

    private function hasAppointment(Lead $lead): bool
    {
        return Appointment::withoutGlobalScopes()->where('lead_id', $lead->id)->whereIn('status', Appointment::LIVE)->exists();
    }

    /** Asked not to be contacted on any channel they could be reached by. */
    private function unsubscribed(Lead $lead): bool
    {
        $wa = filled($lead->phone_normalized) || filled($lead->phone);
        $mail = filled($lead->email);

        if (! $wa && ! $mail) {
            return false;
        }

        return (! $wa || $lead->whatsapp_opt_out_at !== null) && (! $mail || $lead->email_opt_out_at !== null);
    }

    /** Whether any channel could carry a message: a number or an address that is not opted out or bounced. */
    private function reachable(Lead $lead): bool
    {
        return ($lead->acceptsWhatsapp() && (filled($lead->phone_normalized) || filled($lead->phone))) || $lead->acceptsEmail();
    }

    /** Every message rung has been tried and nobody answered: the rest belongs to a person. */
    private function exhausted(Lead $lead): bool
    {
        $steps = collect($lead->follow_up_plan['steps'] ?? []);
        $reached = $steps->first(fn ($s) => ($s['action']['type'] ?? null) === 'create_call_task' && ($s['key'] ?? '') === '3d');

        return $reached !== null && $reached['scheduled_action_id'] !== null
            && ScheduledAction::withoutGlobalScopes()->where('id', $reached['scheduled_action_id'])->where('status', ScheduledAction::DONE)->exists();
    }

    /** What a step is, apart from when it runs: the kind of action and what it is for. */
    private function signature(array $action): string
    {
        return ($action['type'] ?? '').'|'.($action['purpose'] ?? $action['reason'] ?? '');
    }

    private function hasInbound(Lead $lead): bool
    {
        return LeadMessage::withoutGlobalScopes()->where('lead_id', $lead->id)->where('direction', 'inbound')->exists();
    }

    private function sentSomething(Lead $lead): bool
    {
        return LeadMessage::withoutGlobalScopes()->where('lead_id', $lead->id)->where('direction', 'outbound')->where('status', '!=', 'failed')->exists();
    }
}
