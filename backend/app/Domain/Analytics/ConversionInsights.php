<?php

namespace App\Domain\Analytics;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * Does any of it actually predict a sale?
 *
 * The rest of the product measures effort: how fast the team called, what the
 * scoring engine thought, how many leads each platform sent. None of that says
 * whether the effort paid, and a workspace cannot act on a speed figure until
 * it knows that speed is worth paying for.
 *
 * So both of these put a behaviour on one axis and the outcome on the other.
 * They are the feedback half of the loop: the half that tells a manager which
 * of the other screens to care about.
 */
class ConversionInsights
{
    /**
     * Leads too young to have converted are left out.
     *
     * A lead that arrived this morning has not been won yet and probably has
     * not been lost either, and counting it against a bucket's win rate drags
     * every recent cohort towards zero — which makes "we got slower" and "we
     * got newer" look identical. Thirty days is the default window because it
     * is the one the rest of the dashboard already uses.
     */
    public const MATURITY_DAYS = 30;

    /**
     * How quickly the first contact happened, against how often it was won.
     *
     * "Never contacted" is a bucket, not an omission. It is usually the largest
     * one and always the worst performing, and a chart of only the leads
     * somebody got round to calling is a chart of the team's good days.
     *
     * @return array{buckets: array<int, array{bucket:string, leads:int, won:int, win_rate:?float}>, excluded:int, matured_before:string}
     */
    public function bySpeed(EloquentBuilder|Builder $query, int $maturityDays = self::MATURITY_DAYS): array
    {
        $cutoff = now()->subDays($maturityDays);

        $elapsed = 'extract(epoch from (leads.first_contact_at - leads.created_at))';

        /*
         * A first contact stamped before the lead arrived is a data defect, not
         * an instant response. Clamping it to zero would quietly award the best
         * bucket to the worst records, so those rows are excluded and counted,
         * and the screen says how many.
         */
        $measurable = fn ($q) => $q->where('leads.created_at', '<', $cutoff)
            ->where(fn ($inner) => $inner
                ->whereNull('leads.first_contact_at')
                ->orWhereRaw('leads.first_contact_at >= leads.created_at'));

        $rows = (clone $query)
            ->where($measurable)
            ->selectRaw(
                "case
                    when leads.first_contact_at is null then 'never'
                    when {$elapsed} < 300 then 'under_5m'
                    when {$elapsed} < 1800 then 'under_30m'
                    when {$elapsed} < 7200 then 'under_2h'
                    when {$elapsed} < 86400 then 'under_24h'
                    else 'over_24h'
                 end as bucket"
            )
            ->selectRaw('count(*) as leads')
            ->selectRaw('count(*) filter (where leads.won_at is not null) as won')
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        $excluded = (clone $query)
            ->where('leads.created_at', '<', $cutoff)
            ->whereNotNull('leads.first_contact_at')
            ->whereRaw('leads.first_contact_at < leads.created_at')
            ->count();

        // Fixed order, always every bucket: a missing one reads as "no leads
        // were that slow", which is the opposite of what an absent row means.
        $order = ['under_5m', 'under_30m', 'under_2h', 'under_24h', 'over_24h', 'never'];

        return [
            'buckets' => array_map(
                fn (string $bucket) => ['bucket' => $bucket] + $this->rate($rows->get($bucket)),
                $order
            ),
            'excluded' => $excluded,
            'matured_before' => $cutoff->toDateString(),
        ];
    }

    /**
     * What the scoring engine predicted, against what happened.
     *
     * The one chart that can say the score is wrong. If the eighty-plus band
     * does not win more often than the twenty-minus band, the weights are
     * decoration and every rota built on them is sorting leads at random.
     *
     * @return array{bands: array<int, array{band:string, leads:int, won:int, win_rate:?float}>, matured_before:string}
     */
    public function byScore(EloquentBuilder|Builder $query, int $maturityDays = self::MATURITY_DAYS): array
    {
        $cutoff = now()->subDays($maturityDays);

        $rows = (clone $query)
            ->where('leads.created_at', '<', $cutoff)
            ->selectRaw(
                "case
                    when leads.score >= 80 then '80_100'
                    when leads.score >= 60 then '60_79'
                    when leads.score >= 40 then '40_59'
                    when leads.score >= 20 then '20_39'
                    else '0_19'
                 end as band"
            )
            ->selectRaw('count(*) as leads')
            ->selectRaw('count(*) filter (where leads.won_at is not null) as won')
            ->groupBy('band')
            ->get()
            ->keyBy('band');

        $order = ['80_100', '60_79', '40_59', '20_39', '0_19'];

        return [
            'bands' => array_map(
                fn (string $band) => ['band' => $band] + $this->rate($rows->get($band)),
                $order
            ),
            'matured_before' => $cutoff->toDateString(),
        ];
    }

    /**
     * A rate is null when there is nothing to divide.
     *
     * Zero would read as "none of these ever convert", which is a finding. An
     * empty bucket is not a finding; it is an empty bucket.
     *
     * @return array{leads:int, won:int, win_rate:?float}
     */
    private function rate(mixed $row): array
    {
        $leads = (int) ($row->leads ?? 0);
        $won = (int) ($row->won ?? 0);

        return [
            'leads' => $leads,
            'won' => $won,
            'win_rate' => $leads > 0 ? round(($won / $leads) * 100, 1) : null,
        ];
    }
}
