<?php

namespace App\Domain\Tracking;

use App\Domain\Analytics\LeadFunnel;
use App\Models\Lead;
use App\Models\TrackingEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Everything a link — or all of a project's links — did, from the first click to the riyal.
 *
 * Two sources, never mixed up: what visitors did (the events on the trail) and what became of the leads whose FIRST touch
 * was these links (the lead funnel, the one every other screen uses — a won lead was, by definition, qualified, interested,
 * visited and reserved). The rates are each step over the one it comes from, and the funnel says where people were lost.
 * Money is returned only when the caller may see it.
 */
class LinkAnalytics
{
    /** The funnel, in order: what a visitor did, then what became of the lead. */
    public const FUNNEL = ['click', 'view', 'form_start', 'submit', 'qualified', 'interested', 'visit', 'reservation', 'won'];

    /**
     * @param  list<int>  $linkIds
     * @return array{counts:array<string,mixed>, rates:array<string,mixed>, funnel:list<array<string,mixed>>, trend:list<array<string,mixed>>}
     */
    public function forLinks(array $linkIds, Carbon $from, Carbon $to, bool $withMoney): array
    {
        $counts = $this->counts($linkIds, $from, $to);

        if (! $withMoney) {
            $counts['revenue'] = null;
        }

        return [
            'counts' => $counts,
            'rates' => $this->rates($counts),
            'funnel' => $this->funnel($counts),
            'trend' => $this->trend($linkIds, $from, $to),
        ];
    }

    /** @param  list<int>  $linkIds @return array<string,mixed> */
    private function counts(array $linkIds, Carbon $from, Carbon $to): array
    {
        if ($linkIds === []) {
            return $this->empty();
        }

        $e = TrackingEvent::query()->whereIn('tracking_link_id', $linkIds)->whereBetween('occurred_at', [$from, $to])
            ->selectRaw("
                count(*) filter (where event_type = 'click' or meta->>'via' = 'link') as clicks,
                count(distinct visitor_id) as unique_visitors,
                count(*) filter (where event_type = 'visit') as landing_views,
                count(*) filter (where event_type = 'form_start') as form_starts,
                count(*) filter (where event_type = 'form_submit') as form_submissions,
                count(*) filter (where event_type = 'whatsapp_click') as whatsapp,
                count(*) filter (where event_type = 'call_click') as calls
            ")->first();

        $leads = LeadFunnel::counts(Lead::query()->reorder()->whereIn('first_touch_link_id', $linkIds)->whereBetween('leads.created_at', [$from, $to]));
        $revenue = (int) Lead::query()->reorder()->whereIn('first_touch_link_id', $linkIds)->whereBetween('leads.created_at', [$from, $to])->where('leads.status', 'won')->sum('deal_value_minor');

        return [
            'clicks' => (int) $e->clicks,
            'unique_visitors' => (int) $e->unique_visitors,
            'landing_views' => (int) $e->landing_views,
            'form_starts' => (int) $e->form_starts,
            'form_submissions' => (int) $e->form_submissions,
            'leads' => (int) $leads['steps']['leads'],
            'qualified' => (int) $leads['steps']['qualified'],
            'interested' => (int) $leads['steps']['interested'],
            'whatsapp' => (int) $e->whatsapp,
            'calls' => (int) $e->calls,
            // Leads with a visit booked (whether or not it has happened) — and, apart, those that happened.
            'appointments' => (int) $leads['booked'],
            'visits' => (int) $leads['steps']['visit'],
            'reservations' => (int) $leads['steps']['reservation'],
            'won' => (int) $leads['steps']['won'],
            'revenue' => round($revenue / 100, 2),
        ];
    }

    /** @return array<string,mixed> */
    private function empty(): array
    {
        return array_fill_keys(['clicks', 'unique_visitors', 'landing_views', 'form_starts', 'form_submissions', 'leads', 'qualified', 'interested', 'whatsapp', 'calls', 'appointments', 'visits', 'reservations', 'won'], 0) + ['revenue' => 0.0];
    }

    /**
     * @param  array<string,mixed>  $c
     * @return array<string,mixed>
     */
    private function rates(array $c): array
    {
        $pct = fn (int|float $part, int|float $whole) => $whole > 0 ? round($part / $whole * 100, 1) : null;
        $per = fn (?float $money, int $n) => $money !== null && $n > 0 ? round($money / $n, 2) : null;

        return [
            'view_rate' => $pct($c['landing_views'], $c['clicks']),
            'form_start_rate' => $pct($c['form_starts'], $c['landing_views']),
            'completion_rate' => $pct($c['form_submissions'], $c['form_starts']),
            'lead_rate' => $pct($c['leads'], $c['clicks']),
            'qualification_rate' => $pct($c['qualified'], $c['leads']),
            'interested_rate' => $pct($c['interested'], $c['leads']),
            'appointment_rate' => $pct($c['appointments'], $c['leads']),
            'reservation_rate' => $pct($c['reservations'], $c['leads']),
            'close_rate' => $pct($c['won'], $c['leads']),
            'revenue_per_lead' => $per($c['revenue'], $c['leads']),
            'revenue_per_click' => $per($c['revenue'], $c['clicks']),
        ];
    }

    /**
     * Click → view → form start → submit → qualified → interested → visit → reservation → won, with the share lost at each step.
     *
     * @param  array<string,mixed>  $c
     * @return list<array<string,mixed>>
     */
    private function funnel(array $c): array
    {
        $values = [$c['clicks'], $c['landing_views'], $c['form_starts'], $c['form_submissions'], $c['qualified'], $c['interested'], $c['visits'], $c['reservations'], $c['won']];
        $rows = [];

        foreach (self::FUNNEL as $i => $key) {
            $previous = $i === 0 ? null : $values[$i - 1];

            $rows[] = [
                'key' => $key,
                'count' => (int) $values[$i],
                // What did not make it from the step before: never negative (a lead can come from a path that left no earlier trace).
                'drop_off' => $previous !== null && $previous > 0 ? max(0.0, round(($previous - $values[$i]) / $previous * 100, 1)) : null,
                'of_start' => $values[0] > 0 ? round($values[$i] / $values[0] * 100, 1) : null,
            ];
        }

        return $rows;
    }

    /**
     * Day by day: what the link drew and what it turned into. Every day of the period is there, so a chart has no gaps to guess at.
     *
     * @param  list<int>  $linkIds
     * @return list<array<string,mixed>>
     */
    private function trend(array $linkIds, Carbon $from, Carbon $to): array
    {
        if ($linkIds === []) {
            return [];
        }

        $events = TrackingEvent::query()->whereIn('tracking_link_id', $linkIds)->whereBetween('occurred_at', [$from, $to])
            ->selectRaw("(occurred_at)::date as day,
                count(*) filter (where event_type = 'click' or meta->>'via' = 'link') as clicks,
                count(*) filter (where event_type = 'visit') as views,
                count(*) filter (where event_type = 'form_submit') as submissions")
            ->groupBy(DB::raw('(occurred_at)::date'))->get()->keyBy(fn ($r) => (string) $r->day);

        $leads = Lead::query()->reorder()->whereIn('first_touch_link_id', $linkIds)->whereBetween('leads.created_at', [$from, $to])
            ->selectRaw("(leads.created_at)::date as day, count(*) as leads, count(*) filter (where leads.status = 'won') as won")
            ->groupBy(DB::raw('(leads.created_at)::date'))->get()->keyBy(fn ($r) => (string) $r->day);

        $rows = [];

        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            $key = $day->toDateString();
            $rows[] = [
                'date' => $key,
                'clicks' => (int) ($events[$key]->clicks ?? 0), 'views' => (int) ($events[$key]->views ?? 0), 'submissions' => (int) ($events[$key]->submissions ?? 0),
                'leads' => (int) ($leads[$key]->leads ?? 0), 'won' => (int) ($leads[$key]->won ?? 0),
            ];
        }

        return $rows;
    }
}
