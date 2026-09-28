<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

enum SubscriptionPlan: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Annual = 'annual';

    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Annual => 12,
        };
    }

    /**
     * The last day of service (inclusive) for a term starting on the given day.
     * Uses calendar months without overflow: a monthly plan from Jan 31 renews
     * on Feb 28, so its last day of service is Feb 27.
     */
    public function endsOn(CarbonImmutable $startsOn): CarbonImmutable
    {
        return $startsOn->startOfDay()->addMonthsNoOverflow($this->months())->subDay();
    }

    /**
     * Number of days in the term, counting both the first and the last day.
     */
    public function termDays(CarbonImmutable $startsOn): int
    {
        return (int) $startsOn->startOfDay()->diffInDays($this->endsOn($startsOn)) + 1;
    }
}
