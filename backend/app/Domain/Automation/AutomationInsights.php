<?php

namespace App\Domain\Automation;

use App\Models\AutomationRun;
use Illuminate\Support\Facades\DB;

/**
 * What the rules have actually been doing.
 *
 * The automation screen could list twelve enabled rules and tell a workspace
 * nothing about whether any of them fires. The common complaint is not that a
 * rule did something wrong — it is that a rule did nothing at all, quietly,
 * for a month, and the first anybody knew was a customer who never got called.
 *
 * So the question this answers is "is it working", and the honest version of
 * that has three parts: how often rules run, how often they apply rather than
 * skip, and which rules carry the load.
 */
class AutomationInsights
{
    /**
     * Runs per day, split by outcome.
     *
     * Skips are shown, not hidden. A day of two hundred skipped runs and no
     * applications is a workspace whose conditions no longer match its leads —
     * which looks identical to a quiet day if you only plot what applied.
     *
     * @return array<int, array{date:string, applied:int, skipped:int, failed:int}>
     */
    public function daily(int $days = 30): array
    {
        $from = now()->subDays($days - 1)->startOfDay();

        $rows = AutomationRun::query()
            ->where('created_at', '>=', $from)
            ->selectRaw('date(created_at) as day, outcome, count(*) as total')
            ->groupBy(DB::raw('date(created_at)'), 'outcome')
            ->get()
            ->groupBy('day');

        return collect(range($days - 1, 0))
            ->map(function (int $ago) use ($rows) {
                $day = now()->subDays($ago)->toDateString();
                $forDay = $rows->get($day, collect());

                $count = fn (string $outcome) => (int) ($forDay->firstWhere('outcome', $outcome)->total ?? 0);

                return [
                    'date' => $day,
                    'applied' => $count('applied'),
                    'skipped' => $count('skipped'),
                    'failed' => $count('failed'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The rules doing the work, busiest first.
     *
     * A rule that has never applied is the interesting row here, so rules with
     * no applications are kept rather than filtered out — the list exists to
     * surface the ones somebody should look at.
     *
     * @return array<int, array{rule:string, applied:int, skipped:int, failed:int}>
     */
    public function byRule(int $days = 30, int $limit = 10): array
    {
        $from = now()->subDays($days - 1)->startOfDay();

        return AutomationRun::query()
            ->where('automation_runs.created_at', '>=', $from)
            ->join('automation_rules', 'automation_rules.id', '=', 'automation_runs.automation_rule_id')
            ->groupBy('automation_rules.id', 'automation_rules.name')
            ->selectRaw(
                'automation_rules.name as rule,'.
                " count(*) filter (where outcome = 'applied') as applied,".
                " count(*) filter (where outcome = 'skipped') as skipped,".
                " count(*) filter (where outcome = 'failed') as failed"
            )
            ->orderByRaw('count(*) desc')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'rule' => (string) $row->rule,
                'applied' => (int) $row->applied,
                'skipped' => (int) $row->skipped,
                'failed' => (int) $row->failed,
            ])
            ->all();
    }

    /**
     * The headline: how much work the rules took off the team.
     *
     * Counted as actions carried out, not runs — a run that considered a rule
     * and skipped it saved nobody anything, and counting it would let the
     * number grow while the product did less.
     */
    public function summary(int $days = 30): array
    {
        $from = now()->subDays($days - 1)->startOfDay();

        $runs = AutomationRun::query()->where('created_at', '>=', $from)->get(['outcome', 'applied_actions']);

        $applied = $runs->where('outcome', 'applied');

        return [
            'runs' => $runs->count(),
            'applied' => $applied->count(),
            'failed' => $runs->where('outcome', 'failed')->count(),
            'actions' => $applied->sum(fn ($run) => count($run->applied_actions ?? [])),
            'days' => $days,
        ];
    }
}
