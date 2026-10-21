<?php

namespace App\Domain\Insights;

/**
 * What "normal" is for a series, said once so every detector means the same thing by it: the mean
 * and the standard deviation of the days before. Nothing cleverer — an alert has to be explainable
 * in a sentence, and "about 20 a day, and yesterday was 71" is one.
 */
class Baseline
{
    /** @param  list<float|int>  $values @return array{mean:float, std:float, n:int} */
    public static function of(array $values): array
    {
        $n = count($values);

        if ($n === 0) {
            return ['mean' => 0.0, 'std' => 0.0, 'n' => 0];
        }

        $mean = array_sum($values) / $n;
        $variance = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / $n;

        return ['mean' => (float) $mean, 'std' => sqrt($variance), 'n' => $n];
    }

    /**
     * The days that count as history: from the first one that had anything in it. A workspace three
     * days old has no "normal" — the empty days before it existed are not days of silence.
     *
     * @param  list<float|int>  $oldestFirst
     * @return list<float|int>
     */
    public static function fromFirstActivity(array $oldestFirst): array
    {
        $start = null;

        foreach ($oldestFirst as $i => $v) {
            if ($v > 0) {
                $start = $i;
                break;
            }
        }

        return $start === null ? [] : array_slice($oldestFirst, $start);
    }

    /** A count well above its normal: at least double and three deviations out, and by a margin that matters. */
    public static function isSpike(float $value, array $base, float $minimumGap = 5): bool
    {
        return $base['n'] >= 7 && $value - $base['mean'] >= $minimumGap && $value >= $base['mean'] * 2 && $value >= $base['mean'] + 3 * $base['std'];
    }

    /**
     * A count well below its normal: under `$ratio` of it (40% unless said otherwise), two deviations
     * down, from a baseline big enough to mean something.
     */
    public static function isDrop(float $value, array $base, float $minimumMean = 5, float $ratio = 0.4): bool
    {
        return $base['n'] >= 7 && $base['mean'] >= $minimumMean && $value <= $base['mean'] * $ratio && $value <= $base['mean'] - 2 * $base['std'];
    }
}
