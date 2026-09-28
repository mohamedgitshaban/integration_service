<?php

namespace App\Services;

use App\Enums\LedgerEntryType;
use App\Models\Course;
use App\Models\RevenueAllocation;
use App\Models\Subscription;
use App\Support\RevenueMath;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turns served subscription days into instructor earnings.
 *
 * Revenue is earned one day at a time: a subscription paid up front is a
 * liability until each day of access has been delivered. For every day not
 * yet recognised, the day's revenue is split into the platform cut and an
 * instructor pool, and the pool is divided between the instructors of the
 * student's courses in proportion to how many of those courses each teaches.
 */
class RevenueAllocationService
{
    public function __construct(private LedgerService $ledger) {}

    /**
     * Recognise every served day up to and including $through.
     *
     * Idempotent and safe under concurrency: the subscription row is locked
     * and its recognized_through cursor advances in the same transaction as
     * the ledger writes, so a repeated or overlapping call finds nothing due.
     */
    public function allocateThrough(int $subscriptionId, CarbonImmutable $through): ?RevenueAllocation
    {
        return DB::transaction(function () use ($subscriptionId, $through) {
            $subscription = Subscription::query()->lockForUpdate()->findOrFail($subscriptionId);

            $periodStart = $subscription->recognized_through->addDay();
            $periodEnd = $through->startOfDay()->min($subscription->service_ends_on);

            if ($periodEnd->lessThan($periodStart)) {
                return null;
            }

            $earnedBefore = $this->earnedThrough($subscription, $periodStart->subDay());
            $earnedAfter = $this->earnedThrough($subscription, $periodEnd);

            $grossMinor = $earnedAfter - $earnedBefore;
            $platformMinor = RevenueMath::platformCut($earnedAfter, $subscription->platform_share_bps)
                - RevenueMath::platformCut($earnedBefore, $subscription->platform_share_bps);

            $weights = $this->instructorWeights($subscription);

            if ($weights === []) {
                $platformMinor = $grossMinor;
            }

            $shares = RevenueMath::splitByWeight($grossMinor - $platformMinor, $weights);

            $allocation = RevenueAllocation::create([
                'subscription_id' => $subscription->id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'gross_minor' => $grossMinor,
                'platform_minor' => $platformMinor,
                'instructor_pool_minor' => $grossMinor - $platformMinor,
            ]);

            foreach ($shares as $instructorId => $amountMinor) {
                if ($amountMinor === 0) {
                    continue;
                }

                $this->ledger->record(
                    instructorId: $instructorId,
                    type: LedgerEntryType::Earning,
                    amountMinor: $amountMinor,
                    idempotencyKey: "earning:{$allocation->id}:{$instructorId}",
                    occurredOn: $periodEnd,
                    revenueAllocationId: $allocation->id,
                );
            }

            $subscription->update(['recognized_through' => $periodEnd]);

            return $allocation;
        });
    }

    /**
     * Cumulative revenue earned from the start of the term through $day.
     */
    private function earnedThrough(Subscription $subscription, CarbonImmutable $day): int
    {
        $daysServed = (int) $subscription->starts_on->diffInDays($day, false) + 1;

        return RevenueMath::earnedAfterDays($subscription->amount_minor, $subscription->term_days, $daysServed);
    }

    /**
     * Instructor id => number of the subscription's courses they teach.
     *
     * @return array<int, int>
     */
    private function instructorWeights(Subscription $subscription): array
    {
        return Course::query()
            ->join('subscription_courses', 'subscription_courses.course_id', '=', 'courses.id')
            ->where('subscription_courses.subscription_id', $subscription->id)
            ->groupBy('courses.instructor_id')
            ->selectRaw('courses.instructor_id, count(*) as weight')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row) => [(int) $row->instructor_id => (int) $row->weight])
            ->all();
    }
}
