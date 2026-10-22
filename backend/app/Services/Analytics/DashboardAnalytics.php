<?php

namespace App\Services\Analytics;

use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Enums\QualificationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The numbers behind the dashboard's charts.
 *
 * Kept out of the controller because each of these is a shaped question rather
 * than a count, and because each is one query: a dashboard that issues a
 * request per card gets slower every time someone adds a card.
 */
class DashboardAnalytics
{
    /**
     * The funnel, as stages with the conversion between them.
     *
     * Each stage counts leads that *reached* it, not leads sitting in it —
     * otherwise a healthy pipeline where everything has moved on would show an
     * empty funnel and every conversion would read as zero.
     */
    public function funnel(Builder $query): array
    {
        $row = (clone $query)->selectRaw(
            'count(*) as total,
             count(*) filter (where first_contact_at is not null or status <> ?) as contacted,
             count(*) filter (where first_response_at is not null) as replied,
             count(*) filter (where qualification_status = ? or status in (?, ?, ?)) as qualified,
             count(*) filter (where status in (?, ?)) as appointment,
             count(*) filter (where status = ?) as won',
            [
                LeadStatus::New->value,
                QualificationStatus::Qualified->value,
                LeadStatus::Qualified->value, LeadStatus::Appointment->value, LeadStatus::Won->value,
                LeadStatus::Appointment->value, LeadStatus::Won->value,
                LeadStatus::Won->value,
            ],
        )->first();

        $total = (int) $row->total;

        /*
         * "Replied" sits between contacted and qualified because it is the
         * first stage the customer controls — everything above it is effort,
         * everything below it is interest. A funnel without it cannot tell a
         * manager whether the problem is the team or the leads.
         */
        $stages = [
            ['key' => 'total', 'count' => $total],
            ['key' => 'contacted', 'count' => (int) $row->contacted],
            ['key' => 'replied', 'count' => (int) $row->replied],
            ['key' => 'qualified', 'count' => (int) $row->qualified],
            ['key' => 'appointment', 'count' => (int) $row->appointment],
            ['key' => 'won', 'count' => (int) $row->won],
        ];

        return collect($stages)->map(function (array $stage, int $index) use ($stages, $total) {
            $previous = $index === 0 ? $total : $stages[$index - 1]['count'];

            return $stage + [
                // Share of everything that entered — the number compared month
                // to month — and the step conversion, which is where the leak is.
                'of_total' => $total > 0 ? round($stage['count'] / $total * 100, 1) : 0.0,
                'from_previous' => $previous > 0 ? round($stage['count'] / $previous * 100, 1) : 0.0,
            ];
        })->all();
    }

    /** Hot / warm / cold over open leads only — temperature is about work left to do. */
    public function quality(Builder $query): array
    {
        $row = (clone $query)
            ->whereNotIn('status', [LeadStatus::Won->value, LeadStatus::Lost->value])
            ->selectRaw(
                'count(*) filter (where temperature = ?) as hot,
                 count(*) filter (where temperature = ?) as warm,
                 count(*) filter (where temperature = ?) as cold',
                [LeadTemperature::Hot->value, LeadTemperature::Warm->value, LeadTemperature::Cold->value],
            )->first();

        $counts = ['hot' => (int) $row->hot, 'warm' => (int) $row->warm, 'cold' => (int) $row->cold];
        $total = array_sum($counts);

        return collect($counts)->map(fn (int $count, string $key) => [
            'key' => $key,
            'count' => $count,
            'share' => $total > 0 ? round($count / $total * 100, 1) : 0.0,
        ])->values()->all();
    }

    /**
     * How fast the team answers, as a distribution rather than an average.
     *
     * An average hides the shape: a team that answers half its leads in two
     * minutes and half the next day has the same average as one that answers
     * everything in four hours, and those are different problems.
     */
    public function responseTime(Builder $query): array
    {
        $row = (clone $query)->whereNotNull('first_contact_at')->selectRaw(
            "count(*) filter (where first_contact_at - leads.created_at < interval '5 minutes') as under_5,
             count(*) filter (where first_contact_at - leads.created_at >= interval '5 minutes'
                              and first_contact_at - leads.created_at < interval '15 minutes') as under_15,
             count(*) filter (where first_contact_at - leads.created_at >= interval '15 minutes'
                              and first_contact_at - leads.created_at < interval '30 minutes') as under_30,
             count(*) filter (where first_contact_at - leads.created_at >= interval '30 minutes') as over_30,
             avg(extract(epoch from (first_contact_at - leads.created_at))) as average,
             percentile_cont(0.5) within group (
                 order by extract(epoch from (first_contact_at - leads.created_at))
             ) as median"
        )->first();

        $buckets = [
            'under_5' => (int) $row->under_5,
            'under_15' => (int) $row->under_15,
            'under_30' => (int) $row->under_30,
            'over_30' => (int) $row->over_30,
        ];

        $contacted = array_sum($buckets);

        /*
         * Leads nobody has reached are counted separately, never folded in:
         * including them would make a slow team look fast, and dropping them
         * silently would hide the worst number on the page.
         */
        $uncontacted = (int) (clone $query)->whereNull('first_contact_at')->count();

        return [
            'buckets' => collect($buckets)->map(fn (int $count, string $key) => [
                'key' => $key,
                'count' => $count,
                'share' => $contacted > 0 ? round($count / $contacted * 100, 1) : 0.0,
            ])->values()->all(),
            'contacted' => $contacted,
            'uncontacted' => $uncontacted,
            'average_seconds' => $row->average !== null ? (int) round((float) $row->average) : null,
            'median_seconds' => $row->median !== null ? (int) round((float) $row->median) : null,
        ];
    }

    /**
     * The same headline figures over the preceding window of equal length.
     *
     * "+12.4%" means nothing unless the comparison window matches the one being
     * viewed, so the period is derived from the filters rather than fixed.
     *
     * It also means nothing unless the two figures are the same measurement.
     * These counts therefore mirror the summary in DashboardController exactly
     * — current status, not the qualification form, and hot excluding closed
     * leads. They drifted apart once, and the change arrow on three of the five
     * cards was quietly comparing two different things.
     */
    public function comparison(Request $request, callable $baseFor): ?array
    {
        $window = $this->previousWindow($request);

        if (! $window) {
            return null;
        }

        [$from, $to] = $window;

        $row = $baseFor($from, $to)->selectRaw(
            'count(*) as total,
             count(*) filter (where status = ?) as qualified,
             count(*) filter (where temperature = ? and status not in (?, ?)) as hot,
             count(*) filter (where status = ?) as appointments,
             count(*) filter (where status = ?) as won',
            [
                LeadStatus::Qualified->value,
                LeadTemperature::Hot->value, LeadStatus::Won->value, LeadStatus::Lost->value,
                LeadStatus::Appointment->value,
                LeadStatus::Won->value,
            ],
        )->first();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total' => (int) $row->total,
            'qualified' => (int) $row->qualified,
            'hot' => (int) $row->hot,
            'appointments' => (int) $row->appointments,
            'won' => (int) $row->won,
        ];
    }

    /** Leads per day, zero-filled so a quiet day is a dip rather than a gap. */
    public function dailySeries(Builder $query, int $days = 14): array
    {
        $rows = (clone $query)
            ->where('leads.created_at', '>=', now()->subDays($days - 1)->startOfDay())
            ->selectRaw('date(leads.created_at) as day, count(*) as total')
            ->groupBy(DB::raw('date(leads.created_at)'))
            ->pluck('total', 'day');

        return collect(range($days - 1, 0))
            ->map(function (int $ago) use ($rows) {
                $day = now()->subDays($ago)->toDateString();

                return ['key' => $day, 'count' => (int) ($rows[$day] ?? 0)];
            })->all();
    }

    /**
     * What happened each day: leads in, leads qualified, deals won.
     *
     * Each count lands on the day *it* happened, not on the day the lead
     * arrived. The alternative — attributing a win back to the lead's creation
     * date — answers a different question entirely ("how did January's intake
     * do?") and answers it badly on a partial month, because the recent cohorts
     * have not had time to convert and the line falls off a cliff that is not
     * real.
     *
     * @return array<int, array{date:string, leads:int, qualified:int, won:int}>
     */
    public function timeline(Builder $query, int $days = 30): array
    {
        $from = now()->subDays($days - 1)->startOfDay();

        $count = function (string $column) use ($query, $from) {
            return (clone $query)
                ->whereNotNull("leads.{$column}")
                ->where("leads.{$column}", '>=', $from)
                ->selectRaw("date(leads.{$column}) as day, count(*) as total")
                ->groupBy(DB::raw("date(leads.{$column})"))
                ->pluck('total', 'day');
        };

        $leads = $count('created_at');
        $qualified = $count('qualified_at');
        $won = $count('won_at');

        /*
         * Every day in the range, including the empty ones. A series that skips
         * quiet days draws a straight line between the two either side of a
         * weekend, which reads as steady business rather than as a closed one.
         */
        return collect(range($days - 1, 0))
            ->map(function (int $ago) use ($leads, $qualified, $won) {
                $day = now()->subDays($ago)->toDateString();

                return [
                    'date' => $day,
                    'leads' => (int) ($leads[$day] ?? 0),
                    'qualified' => (int) ($qualified[$day] ?? 0),
                    'won' => (int) ($won[$day] ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * How long leads sit at each step before moving.
     *
     * The question a funnel cannot answer. A funnel says ten of forty reached
     * an appointment; it says nothing about the ones that took three weeks to
     * get a first call, which is the part a manager can do something about this
     * afternoon.
     *
     * Median, never mean. One lead from last year that nobody closed drags a
     * mean into uselessness — and the leads that distort it most are exactly
     * the ones a sales floor already knows about. The median is what the middle
     * lead actually experienced.
     *
     * Only leads that *made* the transition are counted. Including the ones
     * still waiting would mix "took two days" with "has been waiting two days
     * and may never move", and the second is a different measurement living on
     * the funnel.
     *
     * @return array<int, array{step:string, median_hours:float|null, count:int}>
     */
    public function velocity(Builder $query): array
    {
        $steps = [
            ['step' => 'to_contact', 'from' => 'leads.created_at', 'to' => 'leads.first_contact_at'],
            ['step' => 'to_qualify', 'from' => 'leads.first_contact_at', 'to' => 'leads.qualified_at'],
            ['step' => 'to_win', 'from' => 'leads.qualified_at', 'to' => 'leads.won_at'],
        ];

        return array_map(function (array $step) use ($query) {
            $row = (clone $query)
                ->whereNotNull($step['from'])
                ->whereNotNull($step['to'])
                // A clock that ran backwards is a data problem, not a fast rep.
                ->whereRaw("{$step['to']} >= {$step['from']}")
                ->selectRaw(
                    'count(*) as total,'.
                    ' percentile_cont(0.5) within group ('.
                    "   order by extract(epoch from ({$step['to']} - {$step['from']}))".
                    ' ) as median_seconds'
                )
                ->first();

            $median = $row?->median_seconds;

            return [
                'step' => $step['step'],
                'median_hours' => $median === null ? null : round(((float) $median) / 3600, 1),
                'count' => (int) ($row?->total ?? 0),
            ];
        }, $steps);
    }

    /**
     * The window immediately before the one being viewed, of equal length.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function previousWindow(Request $request): ?array
    {
        if (! $request->filled('date_from')) {
            /*
             * No range means "everything", which has no previous period. No
             * comparison is shown rather than one measured against an invented
             * window — a made-up baseline is worse than none.
             */
            return null;
        }

        $from = Carbon::parse($request->input('date_from'))->startOfDay();
        $to = $request->filled('date_to') ? Carbon::parse($request->input('date_to'))->endOfDay() : now();
        $days = max(1, (int) $from->diffInDays($to) + 1);

        return [$from->copy()->subDays($days)->startOfDay(), $from->copy()->subDay()->endOfDay()];
    }
}
