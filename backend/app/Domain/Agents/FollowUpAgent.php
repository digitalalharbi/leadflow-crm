<?php

namespace App\Domain\Agents;

use App\Domain\Automation\ActionExecutor;
use App\Domain\Automation\ActionLog;
use App\Models\Lead;

/**
 * Nobody goes quiet for good by accident.
 *
 * Once a day: an open lead nobody has touched for a week, and that the
 * journey is not already looking after, is handed back to automation with the
 * gentle nurture message — so a lead a rep forgot is not a lead lost.
 */
class FollowUpAgent
{
    public const STALE_DAYS = 7;

    public function __construct(
        private readonly ActionExecutor $actions,
        private readonly ActionLog $log,
    ) {}

    public function staleLeadReview(int $limit = 200): int
    {
        $revived = 0;

        Lead::query()
            ->whereNotIn('status', ['won', 'lost', 'invalid'])
            ->where(fn ($q) => $q->whereNull('automation_state')->orWhere('automation_state', '!=', Lead::AUTOMATION_NURTURING))
            ->where('created_at', '<=', now()->subDays(self::STALE_DAYS))
            ->where(fn ($q) => $q->whereNull('last_contact_at')->orWhere('last_contact_at', '<=', now()->subDays(self::STALE_DAYS)))
            ->limit($limit)
            ->get()
            ->each(function (Lead $lead) use (&$revived) {
                $action = ['type' => 'start_nurture', 'rule_name' => 'وكيل المتابعة: العملاء الراكدون'];
                $result = $this->actions->execute($lead, $action);
                $this->log->record($lead, null, 'routine.stale_lead_review', $action, $result['ok'] ? 'completed' : 'failed', [
                    'description' => $result['description'] ?? null,
                    'after' => $result['after'] ?? null,
                ]);
                $revived += $result['ok'] ? 1 : 0;
            });

        return $revived;
    }
}
