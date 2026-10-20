<?php

namespace App\Domain\Conversation;

/**
 * "مليون ونصف", "500 ألف", "1.5m", "between 800k and 1.2 million" → a range.
 *
 * Deliberately conservative: a number counts as money only when it carries a
 * unit (million, thousand, k, m) or sits beside a currency or the word budget.
 * A phone number or a unit count is never mistaken for a price.
 */
final class AmountParser
{
    /** @return array{min:int, max:int}|null */
    public static function parse(string $text): ?array
    {
        $t = Text::normalise($text);

        // Spelled-out halves first: "نص مليون" (half a million), "مليون ونص" (a million and a half).
        $found = [];

        if (preg_match('/\bنص مليون\b|\bنصف مليون\b|half a million/u', $t)) {
            $found[] = 500_000;
        }

        if (preg_match('/\bمليون ونص(?:ف)?\b|million and a half/u', $t)) {
            $found[] = 1_500_000;
        }

        if (preg_match('/\bمليونين(?: ونص(?:ف)?)?\b/u', $t, $m)) {
            $found[] = str_contains($m[0], 'ونص') ? 2_500_000 : 2_000_000;
        }

        // 1.5 مليون / 500 الف / 2m / 800k / 2 million / 500 thousand
        if (preg_match_all('/(\d+(?:[.,]\d+)?)\s*(مليون|الف|ألف|million|thousand|m|k)\b/u', $t, $all, PREG_SET_ORDER)) {
            foreach ($all as [, $number, $unit]) {
                $value = (float) str_replace(',', '.', $number);
                $found[] = (int) round($value * (in_array($unit, ['مليون', 'million', 'm'], true) ? 1_000_000 : 1_000));
            }
        } elseif ($found === [] && preg_match('/\bمليون\b|\bmillion\b/u', $t)) {
            $found[] = 1_000_000;
        }

        // A bare figure beside a currency or the word budget.
        if ($found === [] && preg_match('/(?:ميزانيت\S*|budget|ريال|ر\.?س|sar)\D{0,12}(\d{2,3}(?:[,٬]?\d{3})+)|(\d{2,3}(?:[,٬]?\d{3})+)\s*(?:ريال|ر\.?س|sar)/u', $t, $m)) {
            $found[] = (int) str_replace([',', '٬'], '', $m[1] !== '' ? $m[1] : $m[2]);
        }

        $found = array_values(array_filter($found, fn (int $n) => $n >= 10_000 && $n <= 1_000_000_000));

        if ($found === []) {
            return null;
        }

        return ['min' => min($found), 'max' => max($found)];
    }
}
