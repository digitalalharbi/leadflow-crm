<?php

namespace App\Domain\Automation;

use App\Events\Contracts\LeadDomainEvent;
use App\Jobs\RunScheduledAction;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Lead;
use App\Models\ScheduledAction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Runs a tenant's rules against one event.
 *
 * Three properties matter more than throughput here:
 *
 *  - It records every rule it considered, matched or not. "Why was nothing
 *    sent?" is asked as often as the opposite, and only the skipped rows answer
 *    it.
 *  - One broken rule cannot stop the others, and no rule can lose the lead.
 *    Automation is a convenience layered on top of data that must survive it.
 *  - It never re-enters. An action that changes a stage raises another event;
 *    without a guard, two rules that each move a lead would loop until the
 *    queue worker died.
 */
class AutomationEngine
{
    /** Depth guard against rules that trigger each other. */
    private static int $depth = 0;

    private const MAX_DEPTH = 3;

    public function __construct(
        private ActionLog $logs,
        private ConditionEvaluator $conditions,
        private ActionExecutor $actions,
    ) {}

    public function handle(LeadDomainEvent $event): void
    {
        if (self::$depth >= self::MAX_DEPTH) {
            Log::warning('Automation depth limit reached; stopping.', [
                'lead_id' => $event->lead()->id,
                'trigger' => $event->name(),
            ]);

            return;
        }

        /*
         * A lead that arrived from a sheet is a new lead *and* a new sheet
         * row; a rule may listen for either, and both run.
         */
        $triggers = [$event->name()];

        if ($event->name() === 'lead.created' && $event->lead()->provider === 'google_sheets') {
            $triggers[] = 'lead.sheet_row';
        }

        $rules = AutomationRule::query()
            ->whereIn('trigger', $triggers)
            ->where('is_enabled', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        if ($rules->isEmpty()) {
            return;
        }

        $correlation = (string) Str::uuid();

        self::$depth++;

        try {
            foreach ($rules as $rule) {
                if ($this->apply($rule, $event, $correlation)) {
                    break;
                }
            }
        } finally {
            self::$depth--;
        }
    }

    /** @return bool true when this rule asked that no further rule run. */
    private function apply(AutomationRule $rule, LeadDomainEvent $event, string $correlation): bool
    {
        $startedAt = microtime(true);
        $lead = $event->lead();

        $verdict = $this->conditions->evaluate(
            $lead,
            $rule->conditions ?? [],
            $event->context ?? [],
            $rule->condition_match ?? ConditionEvaluator::MATCH_ALL,
        );

        if (! $verdict['matched']) {
            $this->record($rule, $event, 'skipped', $verdict['reason'], [], $correlation, $startedAt);

            return false;
        }

        $applied = [];
        $failed = false;
        $mode = $rule->mode ?: AutomationRule::MODE_AUTO;

        /*
         * Watching or advising rather than acting. Monitor writes what it
         * would have done and nothing else; Recommend puts the same list in
         * front of the lead's owner as a person's decision. Neither touches
         * the customer.
         */
        if ($mode !== AutomationRule::MODE_AUTO) {
            foreach ($rule->actions ?? [] as $action) {
                $this->logs->record($lead, $rule, $event->name(), $action, $mode === AutomationRule::MODE_MONITOR ? 'skipped' : 'needs_human', [
                    'description' => ($mode === AutomationRule::MODE_MONITOR ? 'مراقبة — كان سيُنفّذ: ' : 'توصية — ينتظر قرارك: ').ActionWords::for($action),
                ], $mode);
                $applied[] = ['type' => $action['type'] ?? 'unknown', 'ok' => true, 'description' => $mode === AutomationRule::MODE_MONITOR ? 'مراقبة فقط.' : 'توصية للمسؤول.'];
            }

            if ($mode === AutomationRule::MODE_RECOMMEND) {
                $this->actions->execute($lead, [
                    'type' => 'notify', 'audience' => 'owner', 'rule_name' => $rule->name,
                    'message' => "توصية من «{$rule->name}»: ".collect($rule->actions ?? [])->map(fn ($a) => ActionWords::for($a))->implode('، '),
                ]);
            }

            $this->record($rule, $event, 'applied', $mode === AutomationRule::MODE_MONITOR ? 'وضع المراقبة' : 'وضع التوصية', $applied, $correlation, $startedAt);
            $rule->forceFill(['times_matched' => $rule->times_matched + 1, 'last_matched_at' => now()])->saveQuietly();

            return (bool) $rule->stops_processing;
        }

        foreach ($rule->actions ?? [] as $action) {
            /*
             * The rule's name, put on the action here rather than stored on it.
             *
             * `notify` has always read `rule_name` and nothing ever set it, so
             * every automated alert was attributed to "قاعدة تلقائية" — a
             * manager with four rules running could not tell which one had just
             * messaged them, which is the one thing that makes an alert
             * actionable. Setting it at execution also means a renamed rule is
             * named correctly in the next alert it sends.
             *
             * Server-side on purpose: the validator has no rule for `rule_name`
             * and `validate()` drops what it was not given rules for, so a
             * client cannot put somebody else's rule name on its own alerts.
             */
            $action['rule_name'] = $rule->name;

            /*
             * An action with a delay is promised, not performed.
             *
             * Automation was entirely immediate, which left the most ordinary
             * sequence in the business unwritable: "if nobody has replied in
             * two days, send a WhatsApp" needs something to happen two days
             * after something did not. The row it writes is what makes the wait
             * visible in the trace, cancellable when the lead closes, and
             * durable across a worker that dies.
             */
            $delay = (int) ($action['delay_minutes'] ?? 0);

            if ($delay > 0) {
                $applied[] = $this->schedule($rule, $lead, $action, $delay, $correlation, $event->name());

                continue;
            }

            $result = $this->actions->execute($lead->fresh() ?? $lead, $action);
            $this->logs->fromResult($lead, $rule, $event->name(), $action, $result);
            $applied[] = [
                'type' => $action['type'] ?? 'unknown',
                'ok' => $result['ok'],
                'description' => $result['description'],
            ];

            $failed = $failed || ! $result['ok'];
        }

        $this->record(
            $rule,
            $event,
            $failed ? 'failed' : 'applied',
            $failed ? 'لم يكتمل تنفيذ إجراء واحد أو أكثر.' : null,
            $applied,
            $correlation,
            $startedAt,
        );

        $rule->forceFill([
            'times_matched' => $rule->times_matched + 1,
            'last_matched_at' => now(),
        ])->saveQuietly();

        return (bool) $rule->stops_processing;
    }

    /**
     * @return array{type:string, ok:bool, description:string}
     */
    private function schedule(
        AutomationRule $rule,
        Lead $lead,
        array $action,
        int $delayMinutes,
        string $correlation,
        ?string $trigger = null,
    ): array {
        $runAt = now()->addMinutes($delayMinutes);

        $scheduled = ScheduledAction::create([
            'lead_id' => $lead->id,
            'automation_rule_id' => $rule->id,
            // Copied, not joined: the rule may be renamed or deleted while this
            // waits, and the trace still has to say what asked for it.
            'rule_name' => $rule->name,
            'action' => $action,
            'run_at' => $runAt,
            'status' => ScheduledAction::PENDING,
            'correlation_id' => $correlation,
        ]);

        $this->logs->record($lead, $rule, $trigger, $action, 'waiting', [
            'description' => 'ينتظر حتى '.$runAt->format('Y-m-d H:i').' — '.ActionWords::for($action),
            'scheduled_action_id' => $scheduled->id,
            'happened_at' => $runAt,
        ]);

        RunScheduledAction::dispatch($scheduled->id)->delay($runAt);

        return [
            'type' => $action['type'] ?? 'unknown',
            'ok' => true,
            'description' => 'مجدول في '.$runAt->toDayDateTimeString().'.',
        ];
    }

    private function record(
        AutomationRule $rule,
        LeadDomainEvent $event,
        string $outcome,
        ?string $reason,
        array $applied,
        string $correlation,
        float $startedAt,
    ): void {
        try {
            AutomationRun::create([
                'lead_id' => $event->lead()->id,
                'automation_rule_id' => $rule->id,
                'trigger' => $event->name(),
                'outcome' => $outcome,
                'reason' => $reason,
                'applied_actions' => $applied,
                'correlation_id' => $correlation,
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);
        } catch (\Throwable $e) {
            // A trace that cannot be written must not take the automation with
            // it — the work is done either way, and losing the record is the
            // lesser failure.
            Log::error('Could not record an automation run.', [
                'rule_id' => $rule->id,
                'lead_id' => $event->lead()->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
