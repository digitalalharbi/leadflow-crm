<?php

namespace App\Domain\Automation;

use App\Events\Contracts\LeadDomainEvent;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
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

        $rules = AutomationRule::query()->forTrigger($event->name())->get();

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

        $verdict = $this->conditions->evaluate($lead, $rule->conditions ?? []);

        if (! $verdict['matched']) {
            $this->record($rule, $event, 'skipped', $verdict['reason'], [], $correlation, $startedAt);

            return false;
        }

        $applied = [];
        $failed = false;

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

            $result = $this->actions->execute($lead->fresh() ?? $lead, $action);
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
