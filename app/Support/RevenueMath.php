<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Integer-only money arithmetic for revenue recognition and splitting.
 *
 * Every function works on cumulative totals or returns parts that sum back to
 * the input exactly, so rounding can never create or lose a piastre.
 */
final class RevenueMath
{
    /**
     * Revenue earned once $daysServed of a $termDays term have been served.
     *
     * Floors the pro-rata value and returns the full amount on the last day.
     * Recognising a period as earned(end) - earned(start - 1) therefore sums
     * to exactly $amountMinor over the term, however the days are batched.
     */
    public static function earnedAfterDays(int $amountMinor, int $termDays, int $daysServed): int
    {
        if ($daysServed <= 0) {
            return 0;
        }

        if ($daysServed >= $termDays) {
            return $amountMinor;
        }

        return intdiv($amountMinor * $daysServed, $termDays);
    }

    /**
     * The platform's cut of a cumulative earned amount, floored, so any
     * fractional piastre goes to the instructors.
     */
    public static function platformCut(int $earnedMinor, int $shareBps): int
    {
        return intdiv($earnedMinor * $shareBps, 10_000);
    }

    /**
     * Split $totalMinor across keys in proportion to their weights using the
     * largest-remainder method. Leftover piastres go to the largest fractional
     * remainders; ties go to the lowest key, so the result is deterministic.
     *
     * @param  array<int, int>  $weights  key => non-negative weight
     * @return array<int, int> key => amount, sorted by key, summing to $totalMinor
     */
    public static function splitByWeight(int $totalMinor, array $weights): array
    {
        if ($totalMinor < 0) {
            throw new InvalidArgumentException('Cannot split a negative amount.');
        }

        $weights = array_filter($weights, fn (int $weight) => $weight > 0);

        if ($weights === []) {
            if ($totalMinor > 0) {
                throw new InvalidArgumentException('Cannot split a positive amount with no positive weights.');
            }

            return [];
        }

        ksort($weights);
        $weightSum = array_sum($weights);
        $shares = [];
        $remainders = [];

        foreach ($weights as $key => $weight) {
            $shares[$key] = intdiv($totalMinor * $weight, $weightSum);
            $remainders[$key] = ($totalMinor * $weight) % $weightSum;
        }

        $order = array_keys($remainders);
        usort($order, fn (int $a, int $b) => ($remainders[$b] <=> $remainders[$a]) ?: ($a <=> $b));

        foreach (array_slice($order, 0, $totalMinor - array_sum($shares)) as $key) {
            $shares[$key]++;
        }

        return $shares;
    }
}
