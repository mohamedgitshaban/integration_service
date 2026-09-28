<?php

namespace Tests\Unit;

use App\Enums\SubscriptionPlan;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SubscriptionPlanTest extends TestCase
{
    /**
     * @return array<string, array{SubscriptionPlan, string, string, int}>
     */
    public static function terms(): array
    {
        return [
            'monthly mid-month' => [SubscriptionPlan::Monthly, '2026-03-15', '2026-04-14', 31],
            'monthly from Jan 31 does not overflow into March' => [SubscriptionPlan::Monthly, '2026-01-31', '2026-02-27', 28],
            'quarterly' => [SubscriptionPlan::Quarterly, '2026-01-01', '2026-03-31', 90],
            'annual in a normal year' => [SubscriptionPlan::Annual, '2026-01-01', '2026-12-31', 365],
            'annual spanning a leap day' => [SubscriptionPlan::Annual, '2027-06-01', '2028-05-31', 366],
        ];
    }

    #[DataProvider('terms')]
    public function test_term_end_and_length_are_inclusive_calendar_days(
        SubscriptionPlan $plan,
        string $startsOn,
        string $expectedEndsOn,
        int $expectedTermDays,
    ): void {
        $start = CarbonImmutable::parse($startsOn);

        $this->assertSame($expectedEndsOn, $plan->endsOn($start)->toDateString());
        $this->assertSame($expectedTermDays, $plan->termDays($start));
    }
}
