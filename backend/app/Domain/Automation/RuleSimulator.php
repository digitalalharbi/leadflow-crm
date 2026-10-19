<?php

namespace App\Domain\Automation;

use App\Models\AutomationRule;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * "If this rule had run over the last seven days" — without running it.
 *
 * Finds the leads the rule's trigger would have fired for in the window,
 * tests each against the rule's own conditions (with the rule's own match
 * mode), and tallies what its actions would have done: messages, stage
 * changes, hand-offs to a person. Messages the guardrails would have held —
 * quiet hours, repeats, a paused project — are counted apart, because a rule
 * that "sends 300 messages" and sends 40 is a different rule.
 *
 * Nothing is written, sent, queued or notified. Conditions are read against
 * each lead's *current* state, which is said plainly on the screen: it is an
 * estimate of volume, not a replay of history.
 */
class RuleSimulator
{
    public const DAYS = 7;

    /** More than this and the answer is an estimate; the result says so. */
    public const CAP = 500;

    private const MESSAGES = ['send_whatsapp', 'send_email', 'ask_qualification', 'request_appointment', 'start_nurture'];

    public function __construct(private ConditionEvaluator $evaluator, private Guardrails $guardrails) {}

    /** @return array<string,mixed> */
    public function run(AutomationRule $rule, User $user): array
    {
        $since = now()->subDays(self::DAYS);
        $candidates = $this->candidates($rule->trigger, $since, $user);

        $matched = $candidates->filter(fn (Lead $lead) => $this->evaluator->evaluate(
            $lead, $rule->conditions ?? [], [], $rule->condition_match ?? ConditionEvaluator::MATCH_ALL,
        )['matched']);

        $actions = collect($rule->actions ?? []);
        $totals = ['whatsapp' => 0, 'email' => 0, 'files' => 0];
        $held = [];
        $stages = [];
        $escalations = 0;
        $notifications = 0;
        $other = [];
        $examples = [];

        foreach ($matched as $lead) {
            $did = [];

            foreach ($actions as $action) {
                $type = (string) ($action['type'] ?? '');

                if (in_array($type, self::MESSAGES, true) || ($type === 'share_file' && ($action['send'] ?? true))) {
                    $channel = $type === 'send_email' || ($type === 'share_file' && ($action['channel'] ?? 'whatsapp') === 'email') ? 'email' : 'whatsapp';
                    $verdict = $this->guardrails->check($lead, $channel, $action);

                    if ($verdict['verdict'] === Guardrails::ALLOW) {
                        $key = $type === 'share_file' ? 'files' : $channel;
                        $totals[$key]++;
                        $did[] = $type;
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
                $examples[] = ['id' => $lead->uuid, 'name' => $lead->full_name, 'status' => $lead->status?->value, 'would' => array_values(array_unique($did))];
            }
        }

        return [
            'days' => self::DAYS,
            'mode' => $rule->mode,
            'trigger_events' => $candidates->count(),
            'capped' => $candidates->count() >= self::CAP,
            'leads_affected' => $matched->count(),
            'messages' => ['whatsapp' => $totals['whatsapp'], 'email' => $totals['email'], 'files' => $totals['files'], 'total' => array_sum($totals), 'held' => $held],
            'stage_changes' => ['total' => array_sum($stages), 'to' => $stages],
            'escalations' => $escalations,
            'notifications' => $notifications,
            'other' => $other,
            'examples' => $examples,
            // Said once, in the data: the screen words it.
            'basis' => 'current_state',
        ];
    }

    /**
     * The leads the trigger would have fired for in the window. Each trigger is
     * mapped to the fact the product records when it happens; one with no such
     * record falls back to leads touched in the window.
     *
     * @return Collection<int,Lead>
     */
    private function candidates(string $trigger, $since, User $user): Collection
    {
        $leads = fn (): Builder => Lead::query()->visibleTo($user)->limit(self::CAP);

        $viaActivity = fn (array $types) => $leads()->whereIn('id', LeadActivity::query()->whereIn('type', $types)->where('created_at', '>=', $since)->select('lead_id'))->get();

        return match ($trigger) {
            'lead.created', 'lead.sheet_row' => $leads()->where('created_at', '>=', $since)->when($trigger === 'lead.sheet_row', fn ($q) => $q->where('provider', 'google_sheets'))->get(),
            'lead.replied' => $leads()->where('first_response_at', '>=', $since)->get(),
            'lead.qualified' => $leads()->where('qualified_at', '>=', $since)->get(),
            'lead.won' => $leads()->where('won_at', '>=', $since)->get(),
            'lead.lost' => $leads()->where('lost_at', '>=', $since)->get(),
            'lead.assigned' => $leads()->where('assigned_at', '>=', $since)->get(),
            'lead.stage_changed' => $viaActivity(['status_changed']),
            'lead.score_changed' => $viaActivity(['score_changed']),
            'lead.appointment_created' => $viaActivity(['appointment_created']),
            'lead.re_engaged' => $viaActivity(['re_engaged']),
            'lead.call_completed', 'lead.outcome_recorded' => $viaActivity(['called', 'no_answer']),
            'lead.sla_breached' => $leads()->where('sla_reminded_at', '>=', $since)->get(),
            'lead.no_reply' => $leads()->where('needs_human_reason', 'no_reply')->where('handed_off_at', '>=', $since)->get(),
            default => $leads()->where('updated_at', '>=', $since)->get(),
        };
    }
}
