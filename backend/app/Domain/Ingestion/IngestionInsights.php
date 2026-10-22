<?php

namespace App\Domain\Ingestion;

use App\Models\LeadSourceEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Whether leads are still arriving, and how many of them are the same lead.
 *
 * The integrations screen can list four connected sources and say nothing about
 * whether any of them delivered anything this week. That is the failure that
 * costs money quietly: a form stops posting, nobody notices for eleven days,
 * and the spend carries on. A list of green badges is not evidence of arrival.
 *
 * Duplicates are plotted beside arrivals rather than netted out of them. A day
 * where sixty events arrived and fifty were duplicates is a broken integration
 * posting the same payload repeatedly — and it reads exactly like a good day if
 * you only plot the leads that were kept.
 */
class IngestionInsights
{
    /**
     * Events per day, split into new leads and duplicates.
     *
     * Every day in the window is emitted, including the empty ones. A series
     * that skips silent days draws a straight line across an outage and makes
     * the gap disappear — which is the one thing this chart exists to show.
     *
     * @return array<int, array{date:string, accepted:int, duplicates:int}>
     */
    public function daily(int $days = 30): array
    {
        $from = now()->subDays($days - 1)->startOfDay();

        $rows = LeadSourceEvent::query()
            ->where('received_at', '>=', $from)
            ->selectRaw('date(received_at) as day')
            ->selectRaw('count(*) filter (where not was_duplicate) as accepted')
            ->selectRaw('count(*) filter (where was_duplicate) as duplicates')
            ->groupBy(DB::raw('date(received_at)'))
            ->get()
            ->keyBy('day');

        return collect(range($days - 1, 0))
            ->map(function (int $ago) use ($rows) {
                $day = now()->subDays($ago)->toDateString();
                $row = $rows->get($day);

                return [
                    'date' => $day,
                    'accepted' => (int) ($row->accepted ?? 0),
                    'duplicates' => (int) ($row->duplicates ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Per source: how much it delivered, how much of that was repeated, and
     * when it was last heard from.
     *
     * `last_received_at` is the column a reader actually acts on. A source with
     * a healthy total and nothing for nine days is the broken one, and totals
     * alone cannot tell you that.
     *
     * @return array<int, array{provider:string, platform:?string, accepted:int, duplicates:int, last_received_at:?string}>
     */
    public function byProvider(int $days = 30): array
    {
        $from = now()->subDays($days - 1)->startOfDay();

        return LeadSourceEvent::query()
            ->where('received_at', '>=', $from)
            ->selectRaw('provider, platform')
            ->selectRaw('count(*) filter (where not was_duplicate) as accepted')
            ->selectRaw('count(*) filter (where was_duplicate) as duplicates')
            ->selectRaw('max(received_at) as last_received_at')
            ->groupBy('provider', 'platform')
            ->orderByDesc(DB::raw('count(*)'))
            ->get()
            ->map(fn ($row) => [
                'provider' => (string) $row->provider,
                'platform' => $row->platform,
                'accepted' => (int) $row->accepted,
                'duplicates' => (int) $row->duplicates,
                /*
                 * ISO 8601 with an offset, never the raw 'Y-m-d H:i:s'.
                 *
                 * Postgres hands back a naked timestamp; JavaScript parses a
                 * naked timestamp as *local* time. Against a UTC database and
                 * a browser in Asia/Riyadh that shifts every reading by three
                 * hours, so a source that delivered a minute ago reported as
                 * "آخر عميل منذ 3 ساعات" — and in the other direction it would
                 * hide three hours of a real outage.
                 */
                'last_received_at' => $row->last_received_at
                    ? Carbon::parse($row->last_received_at)->toIso8601String()
                    : null,
            ])
            ->all();
    }

    /**
     * The headline pair: how many arrived, and what share of them were already
     * known.
     *
     * The rate is null rather than zero when nothing arrived. "0% duplicates"
     * on a dead integration reads as perfect health.
     *
     * @return array{total:int, accepted:int, duplicates:int, duplicate_rate:?float}
     */
    public function summary(int $days = 30): array
    {
        $from = now()->subDays($days - 1)->startOfDay();

        $row = LeadSourceEvent::query()
            ->where('received_at', '>=', $from)
            ->selectRaw('count(*) as total')
            ->selectRaw('count(*) filter (where not was_duplicate) as accepted')
            ->selectRaw('count(*) filter (where was_duplicate) as duplicates')
            ->first();

        $total = (int) ($row->total ?? 0);
        $duplicates = (int) ($row->duplicates ?? 0);

        return [
            'total' => $total,
            'accepted' => (int) ($row->accepted ?? 0),
            'duplicates' => $duplicates,
            'duplicate_rate' => $total > 0 ? round(($duplicates / $total) * 100, 1) : null,
        ];
    }
}
