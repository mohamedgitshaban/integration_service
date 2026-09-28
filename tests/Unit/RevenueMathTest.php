<?php

namespace Tests\Unit;

use App\Support\RevenueMath;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RevenueMathTest extends TestCase
{
    public function test_nothing_is_earned_before_the_first_day_is_served(): void
    {
        $this->assertSame(0, RevenueMath::earnedAfterDays(29_900, 30, 0));
        $this->assertSame(0, RevenueMath::earnedAfterDays(29_900, 30, -5));
    }

    public function test_the_full_amount_is_earned_on_the_last_day_even_when_it_does_not_divide_evenly(): void
    {
        $this->assertSame(100_001, RevenueMath::earnedAfterDays(100_001, 365, 365));
        $this->assertSame(100_001, RevenueMath::earnedAfterDays(100_001, 365, 400));
    }

    public function test_partial_terms_are_floored_pro_rata(): void
    {
        $this->assertSame(33_333, RevenueMath::earnedAfterDays(100_000, 3, 1));
        $this->assertSame(66_666, RevenueMath::earnedAfterDays(100_000, 3, 2));
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function awkwardTerms(): array
    {
        return [
            'annual, prime amount' => [100_003, 365],
            'annual leap year' => [599_999, 366],
            'quarterly' => [89_999, 90],
            'amount smaller than term' => [7, 31],
        ];
    }

    #[DataProvider('awkwardTerms')]
    public function test_daily_recognition_sums_to_exactly_the_amount_paid(int $amountMinor, int $termDays): void
    {
        $total = 0;

        for ($day = 1; $day <= $termDays; $day++) {
            $delta = RevenueMath::earnedAfterDays($amountMinor, $termDays, $day)
                - RevenueMath::earnedAfterDays($amountMinor, $termDays, $day - 1);

            $this->assertGreaterThanOrEqual(0, $delta);
            $total += $delta;
        }

        $this->assertSame($amountMinor, $total);
    }

    public function test_platform_cut_is_floored_so_the_fraction_goes_to_instructors(): void
    {
        $this->assertSame(3_000, RevenueMath::platformCut(10_000, 3_000));
        $this->assertSame(0, RevenueMath::platformCut(3, 3_000));
        $this->assertSame(29_999, RevenueMath::platformCut(99_999, 3_000));
    }

    public function test_split_is_proportional_to_weights(): void
    {
        $this->assertSame([10 => 7_000, 20 => 3_000], RevenueMath::splitByWeight(10_000, [20 => 3, 10 => 7]));
    }

    public function test_split_hands_leftover_piastres_to_the_largest_remainders(): void
    {
        // 100 * 1/6 = 16.67, 100 * 2/6 = 33.33, 100 * 3/6 = 50: one piastre left, goes to key 1.
        $this->assertSame([1 => 17, 2 => 33, 3 => 50], RevenueMath::splitByWeight(100, [1 => 1, 2 => 2, 3 => 3]));
    }

    public function test_split_breaks_ties_by_lowest_key_deterministically(): void
    {
        $this->assertSame([4 => 1, 7 => 1, 9 => 0], RevenueMath::splitByWeight(2, [9 => 1, 7 => 1, 4 => 1]));
    }

    public function test_split_ignores_zero_weights(): void
    {
        $this->assertSame([1 => 100], RevenueMath::splitByWeight(100, [1 => 1, 2 => 0]));
    }

    public function test_split_of_zero_is_all_zero(): void
    {
        $this->assertSame([1 => 0, 2 => 0], RevenueMath::splitByWeight(0, [1 => 1, 2 => 1]));
    }

    public function test_split_always_sums_to_the_total(): void
    {
        mt_srand(42);

        for ($i = 0; $i < 500; $i++) {
            $weights = [];
            for ($k = 1, $n = mt_rand(1, 12); $k <= $n; $k++) {
                $weights[$k * 13] = mt_rand(0, 9);
            }
            $weights[1] = mt_rand(1, 9);
            $total = mt_rand(0, 10_000_000);

            $shares = RevenueMath::splitByWeight($total, $weights);

            $this->assertSame($total, array_sum($shares));
            $this->assertEmpty(array_filter($shares, fn (int $share) => $share < 0));
        }
    }

    public function test_split_refuses_money_with_nobody_to_receive_it(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RevenueMath::splitByWeight(100, [1 => 0]);
    }

    public function test_split_refuses_negative_amounts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RevenueMath::splitByWeight(-1, [1 => 1]);
    }
}
