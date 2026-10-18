<?php

namespace App\Domain\Agents;

use App\Domain\Campaigns\CampaignMetrics;
use App\Models\AgentReport;
use App\Models\ConversionExport;
use Carbon\CarbonImmutable;

/**
 * Writes the weekly client report and keeps it.
 *
 * A saved report is a record of what was known when it was written: it is not
 * recomputed when someone opens it next month, because the numbers it was sent
 * with are the ones the client read. Every report carries the same notes block,
 * so nobody mistakes platform-reported spend for an invoice.
 */
class ReportsAgent
{
    public const NOTES = [
        'الأرقام مأخوذة من المنصات وقت السحب، والفواتير الرسمية هي المرجع.',
        'قد تختلف المبالغ بسبب فروقات سعر الصرف.',
        'العملاء والمؤهلون والصفقات من سجلات «مهتم»؛ الصرف من حسابات الإعلانات أو مما أُدخل يدويًا.',
    ];

    public function weekly(?int $projectId = null, ?CarbonImmutable $today = null): AgentReport
    {
        $today ??= CarbonImmutable::today();
        $from = $today->subDays(6);

        $metrics = new CampaignMetrics(null, $from, $today);
        $rows = $metrics->rows(['project_id' => $projectId]);
        $previous = new CampaignMetrics(null, $from->subDays(7), $today->subDays(7));
        $before = $previous->totals($previous->rows(['project_id' => $projectId]));

        $byPlatform = collect($rows)->groupBy('platform')->map(fn ($g, $platform) => [
            'platform' => $platform,
            'spend' => $g->every(fn ($r) => $r['spend'] === null) ? null : round($g->sum('spend'), 2),
            'leads' => $g->sum('leads'),
            'qualified' => $g->sum('qualified'),
            'won' => $g->sum('won'),
        ])->values()->all();

        $slim = fn (array $r) => array_intersect_key($r, array_flip(['id', 'name', 'platform', 'spend', 'leads', 'qualified', 'won', 'cpl', 'cpql', 'qualified_rate', 'roas']));

        $feedback = ConversionExport::query()
            ->where('created_at', '>=', $from->startOfDay())
            ->selectRaw('event, count(*) filter (where status = ?) as sent, count(*) as total', [ConversionExport::SENT])
            ->groupBy('event')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->event => ['sent' => (int) $r->sent, 'total' => (int) $r->total]])
            ->all();

        return AgentReport::create([
            'project_id' => $projectId,
            'kind' => 'weekly',
            'period_from' => $from->toDateString(),
            'period_to' => $today->toDateString(),
            'data' => [
                'totals' => $metrics->totals($rows),
                'previous' => $before,
                'by_platform' => $byPlatform,
                'best' => collect($rows)->where('leads', '>=', 1)->sortByDesc('qualified')->take(5)->map($slim)->values()->all(),
                'worst' => collect($rows)->filter(fn ($r) => $r['cpql'] !== null)->sortByDesc('cpql')->take(3)->map($slim)->values()->all(),
                'feedback' => $feedback,
                'notes' => self::NOTES,
            ],
        ]);
    }
}
