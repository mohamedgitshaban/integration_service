<?php

namespace Tests\Feature;

use App\Enums\SubscriptionPlan;
use App\Models\Course;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\RevenueAllocation;
use App\Models\Subscription;
use App\Models\User;
use App\Services\RevenueAllocationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RevenueAllocationTest extends TestCase
{
    use RefreshDatabase;

    private RevenueAllocationService $allocator;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ledger.platform_share_bps' => 3_000]);
        $this->allocator = app(RevenueAllocationService::class);
    }

    public function test_only_served_days_are_recognised(): void
    {
        $instructor = User::factory()->instructor()->create();
        $subscription = $this->subscription(SubscriptionPlan::Quarterly, '2026-01-01', 90_000, [$instructor, 1]);

        $allocation = $this->allocator->allocateThrough($subscription->id, CarbonImmutable::parse('2026-01-10'));

        $this->assertSame(10_000, $allocation->gross_minor);
        $this->assertSame(3_000, $allocation->platform_minor);
        $this->assertSame(7_000, $allocation->instructor_pool_minor);
        $this->assertSame(7_000, $this->balance($instructor)->earned_minor);
        $this->assertSame('2026-01-10', $subscription->fresh()->recognized_through->toDateString());
        $this->assertBalanceMatchesLedger($instructor);
    }

    public function test_allocating_the_same_day_twice_changes_nothing(): void
    {
        $instructor = User::factory()->instructor()->create();
        $subscription = $this->subscription(SubscriptionPlan::Monthly, '2026-01-01', 29_900, [$instructor, 1]);
        $through = CarbonImmutable::parse('2026-01-15');

        $this->allocator->allocateThrough($subscription->id, $through);
        $second = $this->allocator->allocateThrough($subscription->id, $through);

        $this->assertNull($second);
        $this->assertSame(1, RevenueAllocation::count());
        $this->assertSame(1, LedgerEntry::count());
        $this->assertBalanceMatchesLedger($instructor);
    }

    public function test_pool_is_split_by_how_many_of_the_students_courses_each_instructor_teaches(): void
    {
        $twoCourses = User::factory()->instructor()->create();
        $oneCourse = User::factory()->instructor()->create();
        $subscription = $this->subscription(SubscriptionPlan::Quarterly, '2026-01-01', 90_000, [$twoCourses, 2], [$oneCourse, 1]);

        $this->allocator->allocateThrough($subscription->id, CarbonImmutable::parse('2026-01-10'));

        // Pool 7,000 split 2:1 = 4,666.67 / 2,333.33; the extra piastre goes to the larger remainder.
        $this->assertSame(4_667, $this->balance($twoCourses)->earned_minor);
        $this->assertSame(2_333, $this->balance($oneCourse)->earned_minor);
    }

    public function test_daily_recognition_over_a_whole_term_accounts_for_every_piastre(): void
    {
        [$a, $b, $c] = User::factory()->instructor()->count(3)->create();
        $subscription = $this->subscription(SubscriptionPlan::Annual, '2026-01-01', 100_003, [$a, 1], [$b, 1], [$c, 1]);

        for ($day = CarbonImmutable::parse('2026-01-01'); $day->lte('2027-01-05'); $day = $day->addDay()) {
            $this->allocator->allocateThrough($subscription->id, $day);
        }

        $this->assertSame(365, RevenueAllocation::count());
        $this->assertSame(100_003, (int) RevenueAllocation::sum('gross_minor'));
        $this->assertSame(
            100_003,
            (int) RevenueAllocation::sum('platform_minor') + (int) LedgerEntry::sum('amount_minor'),
        );
        $this->assertSame(30_000, (int) RevenueAllocation::sum('platform_minor'));
        $this->assertSame('2026-12-31', $subscription->fresh()->recognized_through->toDateString());

        foreach ([$a, $b, $c] as $instructor) {
            $this->assertBalanceMatchesLedger($instructor);
            $this->assertEqualsWithDelta(70_003 / 3, $this->balance($instructor)->earned_minor, 365);
        }
    }

    public function test_catching_up_in_one_run_recognises_the_same_totals_as_running_daily(): void
    {
        $instructor = User::factory()->instructor()->create();
        $daily = $this->subscription(SubscriptionPlan::Quarterly, '2026-01-01', 89_999, [$instructor, 1]);
        $catchUp = $this->subscription(SubscriptionPlan::Quarterly, '2026-01-01', 89_999, [$instructor, 1]);

        for ($day = CarbonImmutable::parse('2026-01-01'); $day->lte('2026-02-14'); $day = $day->addDay()) {
            $this->allocator->allocateThrough($daily->id, $day);
        }
        $this->allocator->allocateThrough($catchUp->id, CarbonImmutable::parse('2026-02-14'));

        $dailyAllocations = RevenueAllocation::whereBelongsTo($daily)->get();
        $catchUpAllocation = RevenueAllocation::whereBelongsTo($catchUp)->sole();

        $this->assertSame($catchUpAllocation->gross_minor, $dailyAllocations->sum('gross_minor'));
        $this->assertSame($catchUpAllocation->platform_minor, $dailyAllocations->sum('platform_minor'));
    }

    public function test_the_platform_keeps_revenue_when_the_student_has_no_courses(): void
    {
        $subscription = Subscription::factory()
            ->plan(SubscriptionPlan::Monthly, CarbonImmutable::parse('2026-01-01'), 31_000)
            ->create();

        $allocation = $this->allocator->allocateThrough($subscription->id, CarbonImmutable::parse('2026-01-10'));

        $this->assertSame(10_000, $allocation->gross_minor);
        $this->assertSame(10_000, $allocation->platform_minor);
        $this->assertSame(0, $allocation->instructor_pool_minor);
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_recognition_stops_at_the_end_of_service(): void
    {
        $instructor = User::factory()->instructor()->create();
        $subscription = $this->subscription(SubscriptionPlan::Quarterly, '2026-01-01', 90_000, [$instructor, 1]);
        $subscription->update(['service_ends_on' => '2026-01-20']);

        $this->allocator->allocateThrough($subscription->id, CarbonImmutable::parse('2026-03-31'));

        $this->assertSame('2026-01-20', $subscription->fresh()->recognized_through->toDateString());
        $this->assertSame(14_000, $this->balance($instructor)->earned_minor);
    }

    public function test_nothing_is_recognised_before_the_subscription_starts(): void
    {
        $instructor = User::factory()->instructor()->create();
        $subscription = $this->subscription(SubscriptionPlan::Monthly, '2026-02-01', 28_000, [$instructor, 1]);

        $this->assertNull($this->allocator->allocateThrough($subscription->id, CarbonImmutable::parse('2026-01-31')));
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_the_command_is_safe_to_run_repeatedly(): void
    {
        $instructor = User::factory()->instructor()->create();
        $this->subscription(SubscriptionPlan::Quarterly, '2026-01-01', 90_000, [$instructor, 1]);
        $this->subscription(SubscriptionPlan::Monthly, '2026-01-05', 31_000, [$instructor, 1]);

        $this->artisan('revenue:allocate', ['--through' => '2026-01-10'])
            ->expectsOutputToContain('Queued 1 allocation job(s)')
            ->assertSuccessful();
        $this->artisan('revenue:allocate', ['--through' => '2026-01-10'])
            ->expectsOutputToContain('Queued 0 allocation job(s)')
            ->assertSuccessful();

        // 10 days of 90,000/90 plus 6 days of 31,000/31, less the 30% platform cut.
        $this->assertSame(7_000 + 4_200, $this->balance($instructor)->earned_minor);
        $this->assertSame(2, LedgerEntry::count());
        $this->assertBalanceMatchesLedger($instructor);
    }

    /**
     * @param  array{User, int}  ...$instructorCourses  instructor and how many of their courses the student takes
     */
    private function subscription(SubscriptionPlan $plan, string $startsOn, int $amountMinor, array ...$instructorCourses): Subscription
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

    private function balance(User $instructor): InstructorBalance
    {
        return InstructorBalance::where('instructor_id', $instructor->id)->sole();
    }

    private function assertBalanceMatchesLedger(User $instructor): void
    {
        $this->assertSame(
            (int) LedgerEntry::where('instructor_id', $instructor->id)->sum('amount_minor'),
            $this->balance($instructor)->outstandingMinor(),
        );
    }
}
