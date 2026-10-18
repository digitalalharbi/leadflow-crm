<?php

namespace App\Domain\Analytics;

use App\Enums\LeadStatus;
use App\Models\Lead;

/**
 * The few things worth saying about the last month, in order of how much they
 * matter.
 *
 * A reports screen is a wall of tables until somebody tells you which of them
 * to read. This reads them first: what moved, what is best, what is leaking —
 * each as a fact with its numbers, and the interface turns it into a sentence.
 * Deterministic and explainable on purpose: an insight a manager cannot check
 * is an opinion, and every figure here is one the tables below it can confirm.
 *
 * Thirty days against the thirty before, regardless of the filters on the page:
 * insights are the headline, and a headline that changed with a dropdown would
 * be a different headline every time somebody looked.
 *
 * Quiet when there is nothing to say. Too little data produces no insight, not
 * a confident one: "TikTok converts best" from three leads is a coin toss
 * dressed up as a finding.
 */
class ReportInsights
{
    /** The fewest leads a comparison needs before it is worth making. */
    private const MIN_SAMPLE = 5;

    private const DAYS = 30;

    /** @return array<int,array{key:string,tone:string,values:array<string,mixed>}> */
    public function all(int $firstContactTarget): array
    {
        $now = now();
        $from = $now->copy()->subDays(self::DAYS);
        $before = $now->copy()->subDays(self::DAYS * 2);

        return array_values(array_filter([
            $this->momentum($from, $before),
            $this->bestSource($from),
            $this->responseSpeed($from, $firstContactTarget),
            $this->biggestLeak($from),
        ]));
    }

    /** @return array{key:string,tone:string,values:array<string,mixed>}|null */
    private function momentum($from, $before): ?array
    {
        $row = Lead::query()->selectRaw(
            'count(*) filter (where created_at >= ?) as current,
             count(*) filter (where created_at >= ? and created_at < ?) as previous',
            [$from, $before, $from],
        )->first();

        $current = (int) $row->current;
        $previous = (int) $row->previous;

        // Nothing to compare against: a first month has no "change", and 100%
        // growth from zero is arithmetic, not news.
        if ($previous < self::MIN_SAMPLE) {
            return $current > 0
                ? ['key' => 'leads_this_month', 'tone' => 'neutral', 'values' => ['count' => $current]]
                : null;
        }

        $change = round(($current - $previous) / $previous * 100);

        if (abs($change) < 5) {
            return ['key' => 'leads_steady', 'tone' => 'neutral', 'values' => ['count' => $current, 'previous' => $previous]];
        }

        return [
            'key' => $change > 0 ? 'leads_up' : 'leads_down',
            'tone' => $change > 0 ? 'good' : 'warn',
            'values' => ['count' => $current, 'previous' => $previous, 'change' => abs($change)],
        ];
    }

    /**
     * The platform whose leads qualify most, set against the one whose leads
     * qualify least — and only when both have enough leads to mean something
     * and they actually differ.
     *
     * @return array{key:string,tone:string,values:array<string,mixed>}|null
     */
    private function bestSource($from): ?array
    {
        $rows = Lead::query()
            ->whereNotNull('platform')
            ->where('created_at', '>=', $from)
            ->selectRaw(
                'platform, count(*) as total,
                 count(*) filter (where qualification_status = ? or status in (?, ?, ?)) as qualified',
                ['qualified', LeadStatus::Qualified->value, LeadStatus::Appointment->value, LeadStatus::Won->value],
            )
            ->groupBy('platform')
            ->get()
            ->filter(fn ($row) => (int) $row->total >= self::MIN_SAMPLE)
            ->map(fn ($row) => [
                'platform' => $row->platform,
                'total' => (int) $row->total,
                'rate' => round((int) $row->qualified / (int) $row->total * 100),
            ])
            ->sortByDesc('rate')
            ->values();

        if ($rows->count() < 2) {
            return null;
        }

        $best = $rows->first();
        $worst = $rows->last();

        if ($best['rate'] - $worst['rate'] < 10) {
            return null;
        }

        return [
            'key' => 'best_source',
            'tone' => 'good',
            'values' => [
                'best' => $best['platform'], 'best_rate' => $best['rate'],
                'worst' => $worst['platform'], 'worst_rate' => $worst['rate'],
            ],
        ];
    }

    /** @return array{key:string,tone:string,values:array<string,mixed>}|null */
    private function responseSpeed($from, int $target): ?array
    {
        $row = Lead::query()
            ->where('created_at', '>=', $from)
            ->whereNotNull('first_contact_at')
            ->selectRaw(
                'count(*) as n,
                 count(*) filter (where extract(epoch from (first_contact_at - created_at)) / 60 > ?) as slow,
                 percentile_cont(0.5) within group (order by extract(epoch from (first_contact_at - created_at)) / 60) as median',
                [$target],
            )
            ->first();

        $n = (int) $row->n;

        if ($n < self::MIN_SAMPLE) {
            return null;
        }

        $slowShare = round((int) $row->slow / $n * 100);

        return [
            'key' => $slowShare >= 25 ? 'response_slow' : 'response_fast',
            'tone' => $slowShare >= 25 ? 'warn' : 'good',
            'values' => [
                'median' => (int) round((float) $row->median),
                'target' => $target,
                'slow_share' => $slowShare,
            ],
        ];
    }

    /**
     * Between which two steps of the funnel the biggest share of leads is lost.
     *
     * @return array{key:string,tone:string,values:array<string,mixed>}|null
     */
    private function biggestLeak($from): ?array
    {
        $row = Lead::query()
            ->where('created_at', '>=', $from)
            ->selectRaw(
                'count(*) as total,
                 count(*) filter (where first_contact_at is not null) as contacted,
                 count(*) filter (where qualified_at is not null or status in (?, ?, ?)) as qualified,
                 count(*) filter (where status = ?) as won',
                [LeadStatus::Qualified->value, LeadStatus::Appointment->value, LeadStatus::Won->value, LeadStatus::Won->value],
            )
            ->first();

        if ((int) $row->total < self::MIN_SAMPLE * 2) {
            return null;
        }

        $steps = [
            ['from' => 'new', 'to' => 'contacted', 'a' => (int) $row->total, 'b' => (int) $row->contacted],
            ['from' => 'contacted', 'to' => 'qualified', 'a' => (int) $row->contacted, 'b' => (int) $row->qualified],
            ['from' => 'qualified', 'to' => 'won', 'a' => (int) $row->qualified, 'b' => (int) $row->won],
        ];

        $worst = collect($steps)
            ->filter(fn ($s) => $s['a'] >= self::MIN_SAMPLE)
            ->map(fn ($s) => $s + ['lost' => round((1 - $s['b'] / $s['a']) * 100)])
            ->sortByDesc('lost')
            ->first();

        if (! $worst || $worst['lost'] < 30) {
            return null;
        }

        return [
            'key' => 'biggest_leak',
            'tone' => 'warn',
            'values' => ['from' => $worst['from'], 'to' => $worst['to'], 'lost' => $worst['lost']],
        ];
    }
}
