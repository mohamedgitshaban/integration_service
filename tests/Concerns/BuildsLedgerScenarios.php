<?php

namespace Tests\Concerns;

use App\Enums\AllocationKind;
use App\Enums\LedgerEntryType;
use App\Enums\SubscriptionPlan;
use App\Models\Course;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\RevenueAllocation;
use App\Models\Subscription;
use App\Models\User;
use App\Services\LedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait BuildsLedgerScenarios
{
    /**
     * @param  array{User, int}  ...$instructorCourses  instructor and how many of their courses the student takes
     */
    protected function subscription(SubscriptionPlan $plan, string $startsOn, int $amountMinor, array ...$instructorCourses): Subscription
    {
        $subscription = Subscription::factory()
            ->plan($plan, CarbonImmutable::parse($startsOn), $amountMinor)
            ->create();

        foreach ($instructorCourses as [$instructor, $courseCount]) {
            $subscription->courses()->attach(
                Course::factory()->count($courseCount)->for($instructor, 'instructor')->create()
            );
        }

        return $subscription;
    }

    /**
     * Credit an instructor directly, for tests that are about paying out
     * rather than about how the money was earned.
     */
    protected function earn(User $instructor, int $amountMinor): void
    {
        DB::transaction(fn () => app(LedgerService::class)->record(
            $instructor->id,
            LedgerEntryType::Earning,
            $amountMinor,
            'test-earning:'.Str::uuid(),
            CarbonImmutable::today(),
        ));
    }

    protected function balance(User $instructor): InstructorBalance
    {
        return InstructorBalance::where('instructor_id', $instructor->id)->sole();
    }

    protected function assertBalanceMatchesLedger(User $instructor): void
    {
        $this->assertSame(
            (int) LedgerEntry::where('instructor_id', $instructor->id)->sum('amount_minor'),
            $this->balance($instructor)->outstandingMinor(),
        );
    }

    /**
     * Every piastre the student paid ends up with the platform, an instructor,
     * or back with the student: nothing created, nothing lost.
     */
    protected function assertMoneyConserved(Subscription $subscription): void
    {
        $subscription->refresh();
        $allocations = RevenueAllocation::whereBelongsTo($subscription)->get();
        $signed = fn (RevenueAllocation $allocation, int $amount) => $allocation->kind === AllocationKind::Reversal ? -$amount : $amount;

        $platformMinor = $allocations->sum(fn (RevenueAllocation $a) => $signed($a, $a->platform_minor));
        $instructorsMinor = (int) LedgerEntry::whereIn('revenue_allocation_id', $allocations->pluck('id'))->sum('amount_minor');
        $poolMinor = $allocations->sum(fn (RevenueAllocation $a) => $signed($a, $a->instructor_pool_minor));

        $this->assertSame($poolMinor, $instructorsMinor, 'Instructor ledger does not match allocated pools.');
        $this->assertSame(
            $subscription->amount_minor,
            $platformMinor + $instructorsMinor + ($subscription->refund_amount_minor ?? 0),
            'Platform + instructors + refund must equal the amount paid.',
        );
    }
}
