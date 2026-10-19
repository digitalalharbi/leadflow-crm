<?php

namespace App\Domain\Automation;

use App\Models\AutomationActionLog;
use App\Models\AutomationRule;
use App\Models\Lead;
use Illuminate\Support\Facades\Log;

/**
 * Writes the Action History. Never allowed to cost the action it records: a
 * history row that cannot be written is logged and dropped.
 */
class ActionLog
{
    public function record(Lead $lead, ?AutomationRule $rule, ?string $trigger, array $action, string $result, array $extra = [], string $mode = 'auto'): ?AutomationActionLog
    {
        try {
            return AutomationActionLog::create([
                'lead_id' => $lead->id,
                'automation_rule_id' => $rule?->id,
                'scheduled_action_id' => $extra['scheduled_action_id'] ?? null,
                'rule_name' => $rule?->name ?? ($action['rule_name'] ?? null),
                'trigger' => $trigger,
                'action_type' => (string) ($action['type'] ?? 'unknown'),
                'channel' => AutomationActionLog::channelFor($action),
                'mode' => $mode,
                'result' => $result,
                'before' => isset($extra['before']) ? mb_substr((string) $extra['before'], 0, 120) : null,
                'after' => isset($extra['after']) ? mb_substr((string) $extra['after'], 0, 120) : null,
                'description' => $extra['description'] ?? null,
                'happened_at' => $extra['happened_at'] ?? now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('automation.history_write_failed', ['lead' => $lead->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** What an executed action reported, as a history row. */
    public function fromResult(Lead $lead, ?AutomationRule $rule, ?string $trigger, array $action, array $result): void
    {
        $this->record($lead, $rule, $trigger, $action, self::resultFor($action, $result), [
            'before' => $result['before'] ?? null,
            'after' => $result['after'] ?? null,
            'description' => $result['description'] ?? null,
        ]);
    }

    /** A waiting step that has now run (or will not), on the row that said it was waiting. */
    public function resolveScheduled(int $scheduledActionId, array $action, array $result, ?string $cancelledBecause = null): void
    {
        $row = AutomationActionLog::withoutGlobalScopes()->where('scheduled_action_id', $scheduledActionId)->first();

        if (! $row) {
            return;
        }

        $row->forceFill([
            'result' => $cancelledBecause !== null ? AutomationActionLog::SKIPPED : self::resultFor($action, $result),
            'description' => $cancelledBecause ?? ($result['description'] ?? $row->description),
            'before' => $result['before'] ?? $row->before,
            'after' => $result['after'] ?? $row->after,
            'happened_at' => now(),
        ])->save();
    }

    private static function resultFor(array $action, array $result): string
    {
        if (! ($result['ok'] ?? false)) {
            return AutomationActionLog::FAILED;
        }

        return $result['result'] ?? (($action['type'] ?? null) === 'hand_off' && ($result['handed_off'] ?? false)
            ? AutomationActionLog::NEEDS_HUMAN
            : AutomationActionLog::COMPLETED);
    }
}
