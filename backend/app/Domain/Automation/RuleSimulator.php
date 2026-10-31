<?php

namespace App\Domain\Automation;

use App\Models\AutomationRule;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "If this rule had run over the last 7 (or 30) days" — against what actually
 * happened, and without running it.
 *
 * For each lead the rule's trigger would have fired for, the lead is put back
 * the way it was at that moment (stage, score, who had it — see LeadAtTime),
 * tested against the rule's own conditions, and every time-based guardrail is
 * judged as of that moment too: the hour of day, the day's messages already
 * sent, the cooldown, a person already having taken over. What the rule's
 * actions would then have done is tallied: messages, stage changes, hand-offs.
 *
 * "Estimated qualified" is not a promise. It applies what the workspace's own
 * history shows — leads that were messaged automatically qualified at one
 * rate, those that were not at another — to the leads this rule would have
 * reached, and says nothing when there is too little history to compare.
 *
 * Nothing is written, sent, queued or notified.
 */
class RuleSimulator
{
    public const PERIODS = [7, 30];

    /** More than this and the answer is a sample; the result says so. */
    public const CAP = 300;

    /** Each group in the comparison needs at least this many leads before a rate means anything. */
    private const MIN_SAMPLE = 20;

    private const MESSAGES = ['send_whatsapp', 'send_email', 'ask_qualification', 'request_appointment', 'start_nurture'];

    public function __construct(private ConditionEvaluator $evaluator, private Guardrails $guardrails) {}

    /** @return array<string,mixed> */
    public function run(AutomationRule $rule, User $user, int $days = 7): array
    {
        $days = in_array($days, self::PERIODS, true) ? $days : 7;
        $since = now()->subDays($days);
        $events = $this->events($rule->trigger, $since, $user);
        $leads = $events->pluck('lead')->unique('id')->values();
        $past = new LeadAtTime($leads);

        $actions = collect($rule->actions ?? []);
        $totals = ['whatsapp' => 0, 'email' => 0, 'files' => 0];
        $held = $stages = $other = $byDay = [];
        $escalations = $notifications = 0;
        $affected = collect();
        $messaged = collect();
        $examples = [];

        foreach ($events as ['lead' => $lead, 'at' => $at]) {
            $matched = $this->asOf($at, function () use ($rule, $lead, $at, $past, $actions, &$totals, &$held, &$stages, &$other, &$escalations, &$notifications, &$examples) {
                $then = $past->at($lead, $at);

                if (! $this->evaluator->evaluate($then, $rule->conditions ?? [], [], $rule->condition_match ?? ConditionEvaluator::MATCH_ALL)['matched']) {
                    return null;
                }

                $did = [];
                $sent = 0;

                foreach ($actions as $action) {
                    $type = (string) ($action['type'] ?? '');

                    if (in_array($type, self::MESSAGES, true) || ($type === 'share_file' && ($action['send'] ?? true))) {
                        $channel = $type === 'send_email' || ($type === 'share_file' && ($action['channel'] ?? 'whatsapp') === 'email') ? 'email' : 'whatsapp';
                        $verdict = $this->guardrails->check($then, $channel, $action);

                        if ($verdict['verdict'] === Guardrails::ALLOW) {
                            $totals[$type === 'share_file' ? 'files' : $channel]++;
                            $did[] = $type;
                            $sent++;
                        } else {
                            $held[$verdict['code']] = ($held[$verdict['code']] ?? 0) + 1;
                        }

                        continue;
                    }

                    match ($type) {
                        'change_stage' => $stages[(string) ($action['stage'] ?? $action['to'] ?? '?')] = ($stages[(string) ($action['stage'] ?? $action['to'] ?? '?')] ?? 0) + 1,
                        'hand_off', 'create_call_task' => $escalations++,
                        'notify' => $notifications++,
                        default => $other[$type] = ($other[$type] ?? 0) + 1,
                    };

                    if ($type !== '') {
                        $did[] = $type;
                    }
                }

                if (count($examples) < 5) {
                    $examples[] = ['id' => $lead->uuid, 'name' => $lead->full_name, 'status' => $then->status?->value, 'at' => $at->toIso8601String(), 'would' => array_values(array_unique($did))];
                }

                return $sent;
            });

            if ($matched === null) {
                continue;
            }

            $affected->push($lead->id);
            $day = $at->toDateString();
            $byDay[$day] ??= ['date' => $day, 'leads' => 0, 'messages' => 0];
            $byDay[$day]['leads']++;
            $byDay[$day]['messages'] += $matched;

            if ($matched > 0) {
                $messaged->push($lead->id);
            }
        }

        $affectedLeads = $leads->whereIn('id', $affected->all());
        $qualifiedNow = $affectedLeads->filter(fn (Lead $lead) => $this->qualified($lead))->count();

        return [
            'days' => $days,
            'mode' => $rule->mode,
            'trigger_events' => $events->count(),
            'capped' => $events->count() >= self::CAP,
            'leads_affected' => $affected->unique()->count(),
            'messages' => ['whatsapp' => $totals['whatsapp'], 'email' => $totals['email'], 'files' => $totals['files'], 'total' => array_sum($totals), 'held' => $held],
            'stage_changes' => ['total' => array_sum($stages), 'to' => $stages],
            'escalations' => $escalations,
            'notifications' => $notifications,
            'other' => $other,
            'qualified' => ['already' => $qualifiedNow] + $this->estimate($user, $since, $affected->unique()->count(), $messaged->unique()->count()),
            'by_day' => $this->fill($byDay, $days),
            'examples' => $examples,
            // Said once, in the data: the screen words it.
            'basis' => 'history',
        ];
    }

    /** Run something with the clock set to a past moment, and put it back. */
    private function asOf(Carbon $moment, callable $work): mixed
    {
        Carbon::setTestNow($moment);

        try {
            return $work();
        } finally {
            Carbon::setTestNow();
        }
    }

    private function qualified(Lead $lead): bool
    {
        return $lead->qualified_at !== null || in_array($lead->status?->value, ['qualified', 'appointment', 'negotiation', 'won'], true);
    }

    /**
     * What the workspace's own history says about leads that were messaged
     * automatically against those that were not — applied to the leads this
     * rule would have reached. Silent when either group is too small.
     *
     * @return array{estimated:?int, rate_with_message:?float, rate_without:?float, reason:?string}
     */
    private function estimate(User $user, Carbon $since, int $affected, int $messaged): array
    {
        $cohort = Lead::query()->visibleTo($user)->reorder()->where('leads.created_at', '>=', $since)->limit(2000)
            ->get(['id', 'status', 'qualified_at']);
        $withMessage = LeadMessage::withoutGlobalScopes()->whereIn('lead_id', $cohort->pluck('id'))
            ->where('direction', 'outbound')->whereNull('user_id')->pluck('lead_id')->flip();

        [$with, $without] = $cohort->partition(fn (Lead $lead) => isset($withMessage[$lead->id]));

        if ($with->count() < self::MIN_SAMPLE || $without->count() < self::MIN_SAMPLE) {
            return ['estimated' => null, 'rate_with_message' => null, 'rate_without' => null, 'reason' => 'not_enough_history'];
        }

        $rate = fn (Collection $group) => $group->filter(fn (Lead $lead) => $this->qualified($lead))->count() / $group->count();
        $rateWith = $rate($with);
        $rateWithout = $rate($without);

        return [
            'estimated' => (int) round($messaged * $rateWith + ($affected - $messaged) * $rateWithout),
            'rate_with_message' => round($rateWith * 100, 1),
            'rate_without' => round($rateWithout * 100, 1),
            'reason' => null,
        ];
    }

    /** Every day of the window, in order, even the quiet ones. */
    private function fill(array $byDay, int $days): array
    {
        return collect(range($days - 1, 0))->map(function (int $ago) use ($byDay) {
            $date = now()->subDays($ago)->toDateString();

            return $byDay[$date] ?? ['date' => $date, 'leads' => 0, 'messages' => 0];
        })->all();
    }

    /**
     * The leads the trigger would have fired for in the window, each with the
     * moment it would have fired — read from the record the product keeps when
     * that thing happens. A trigger with no such record falls back to leads
     * touched in the window.
     *
     * @return Collection<int,array{lead:Lead, at:Carbon}>
     */
    private function events(string $trigger, Carbon $since, User $user): Collection
    {
        $leads = fn (): Builder => Lead::query()->visibleTo($user)->reorder()->limit(self::CAP);

        $column = fn (string $name, ?callable $narrow = null) => $leads()->where($name, '>=', $since)->orderBy($name)
            ->when($narrow, fn ($q) => $narrow($q))->get()
            ->map(fn (Lead $lead) => ['lead' => $lead, 'at' => Carbon::parse($lead->{$name})]);

        $activity = fn (array $types) => $this->viaActivity($types, $since, $leads);

        return match ($trigger) {
            'lead.created' => $column('created_at'),
            'lead.sheet_row' => $column('created_at', fn ($q) => $q->where('provider', 'google_sheets')),
            'lead.replied' => $column('first_response_at'),
            'lead.qualified' => $column('qualified_at'),
            'lead.won' => $column('won_at'),
            'lead.lost' => $column('lost_at'),
            'lead.assigned' => $column('assigned_at'),
            'lead.sla_breached' => $column('sla_reminded_at'),
            'lead.stage_changed' => $activity(['status_changed']),
            'lead.score_changed' => $activity(['score_changed']),
            'lead.appointment_created' => $activity(['appointment_created']),
            'lead.reservation_created' => $activity(['reservation_created']),
            'lead.re_engaged' => $activity(['re_engaged']),
            'lead.call_completed', 'lead.outcome_recorded' => $activity(['called', 'no_answer']),
            'lead.no_reply' => $column('handed_off_at', fn ($q) => $q->where('needs_human_reason', 'no_reply')),
            default => $column('updated_at'),
        };
    }

    /** @return Collection<int,array{lead:Lead, at:Carbon}> */
    private function viaActivity(array $types, Carbon $since, callable $leads): Collection
    {
        $first = LeadActivity::query()->whereIn('type', $types)->where('created_at', '>=', $since)
            ->selectRaw('lead_id, min(created_at) as at')->groupBy('lead_id')->limit(self::CAP)->pluck('at', 'lead_id');

        return $leads()->whereIn('id', $first->keys())->get()
            ->map(fn (Lead $lead) => ['lead' => $lead, 'at' => Carbon::parse($first[$lead->id])])->sortBy('at')->values();
    }
}
