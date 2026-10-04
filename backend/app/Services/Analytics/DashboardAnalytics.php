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
