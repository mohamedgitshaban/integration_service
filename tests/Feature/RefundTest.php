<?php

namespace Tests\Feature;

use App\Enums\AllocationKind;
use App\Enums\LedgerEntryType;
use App\Enums\RefundPolicy;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\LedgerEntry;
use App\Models\RevenueAllocation;
use App\Models\User;
use App\Services\RefundService;
use App\Services\RevenueAllocationService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLedgerScenarios;
use Tests\TestCase;

class RefundTest extends TestCase
{
    use BuildsLedgerScenarios, RefreshDatabase;

    private RevenueAllocationService $allocator;

    private RefundService $refunds;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ledger.platform_share_bps' => 3_000]);
        $this->allocator = app(RevenueAllocationService::class);
        $this->refunds = app(RefundService::class);
    }

    public function test_a_mid_term_refund_returns_only_unserved_days_and_leaves_instructors_untouched(): void
    {
        $instructor = User::factory()->instructor()->create();
        $subscription = $this->subscription(SubscriptionPlan::Quarterly, '2026-01-01', 90_000, [$instructor, 1]);
        $this->allocator->allocateThrough($subscription->id, CarbonImmutable::parse('2026-01-19'));

        $refunded = $this->refunds->refund($subscription->id, CarbonImmutable::parse('2026-01-20'));
        $this->allocator->allocateThrough($subscription->id, CarbonImmutable::parse('2026-03-31'));

        $this->assertSame(SubscriptionStatus::Refunded, $refunded->status);
        $this->assertSame(71_000, $refunded->refund_amount_minor);
        $this->assertSame('2026-01-19', $refunded->service_ends_on->toDateString());
        $this->assertSame(0, LedgerEntry::where('type', LedgerEntryType::Clawback)->count());
        $this->assertSame(13_300, $this->balance($instructor)->earned_minor);
        $this->assertMoneyConserved($subscription);
    }

    public function test_days_not_yet_allocated_are_recognised_up_to_the_refund_and_no_further(): void
    {
        $instructor = User::factory()->instructor()->create();
        $subscription = $this->subscription(SubscriptionPlan::Quarterly, '2026-01-01', 90_000, [$instructor, 1]);
        $this->allocator->allocateThrough($subscription->id, CarbonImmutable::parse('2026-01-05'));

        $this->refunds->refund($subscription->id, CarbonImmutable::parse('2026-01-20'));
        $this->allocator->allocateThrough($subscription->id, CarbonImmutable::parse('2026-03-31'));

        $this->assertSame(13_300, $this->balance($instructor)->earned_minor);
        $this->assertSame('2026-01-19', $subscription->fresh()->recognized_through->toDateString());
        $this->assertMoneyConserved($subscription);
    }

    public function test_a_backdated_refund_claws_back_days_that_were_already_recognised(): void
    {
        $instructor = User::factory()->instructor()->create();
        $subscription = $this->subscription(SubscriptionPlan::Quarterly, '2026-01-01', 90_000, [$instructor, 1]);
        $this->allocator->allocateThrough($subscription->id, CarbonImmutable::parse('2026-01-30'));

        $refunded = $this->refunds->refund($subscription->id, CarbonImmutable::parse('2026-01-11'));

        $reversal = RevenueAllocation::where('kind', AllocationKind::Reversal)->sole();
        $this->assertSame(80_000, $refunded->refund_amount_minor);
        $this->assertSame(20_000, $reversal->gross_minor);
        $this->assertSame(14_000, $reversal->instructor_pool_minor);
        $this->assertSame(-14_000, (int) LedgerEntry::where('type', LedgerEntryType::Clawback)->sum('amount_minor'));
        $this->assertSame(7_000, $this->balance($instructor)->earned_minor);
        $this->assertSame('2026-01-10', $refunded->recognized_through->toDateString());
        $this->assertBalanceMatchesLedger($instructor);
        $this->assertMoneyConserved($subscription);
    }

    public function test_a_full_refund_reverses_every_instructor_earning_exactly(): void
    {
        [$a, $b, $c] = User::factory()->instructor()->count(3)->create();
        $subscription = $this->subscription(SubscriptionPlan::Annual, '2026-01-01', 100_003, [$a, 1], [$b, 2], [$c, 4]);
        foreach (['2026-01-07', '2026-01-19', '2026-02-02', '2026-02-15'] as $day) {
            $this->allocator->allocateThrough($subscription->id, CarbonImmutable::parse($day));
        }

        $refunded = $this->refunds->refund($subscription->id, CarbonImmutable::parse('2026-02-16'), RefundPolicy::Full);

        $this->assertSame(100_003, $refunded->refund_amount_minor);
        foreach ([$a, $b, $c] as $instructor) {
            $this->assertSame(0, $this->balance($instructor)->earned_minor);
            $this->assertBalanceMatchesLedger($instructor);
        }
        $this->assertMoneyConserved($subscription);
    }

    public function test_a_partial_clawback_never_takes_more_from_an_instructor_than_they_earned(): void
    {
        [$a, $b, $c] = User::factory()->instructor()->count(3)->create();
        $subscription = $this->subscription(SubscriptionPlan::Quarterly, '2026-01-01', 89_999, [$a, 1], [$b, 1], [$c, 1]);
        for ($day = CarbonImmutable::parse('2026-01-01'); $day->lte('2026-02-20'); $day = $day->addDay()) {
            $this->allocator->allocateThrough($subscription->id, $day);
        }

        $this->refunds->refund($subscription->id, CarbonImmutable::parse('2026-01-03'));

        foreach ([$a, $b, $c] as $instructor) {
            $this->assertGreaterThanOrEqual(0, $this->balance($instructor)->earned_minor);
            $this->assertBalanceMatchesLedger($instructor);
        }
        $this->assertMoneyConserved($subscription);
    }

    public function test_refunding_twice_changes_nothing(): void
    {
        $instructor = User::factory()->instructor()->create();
        $subscription = $this->subscription(SubscriptionPlan::Quarterly, '2026-01-01', 90_000, [$instructor, 1]);
        $this->allocator->allocateThrough($subscription->id, CarbonImmutable::parse('2026-01-30'));

        $this->refunds->refund($subscription->id, CarbonImmutable::parse('2026-01-11'));
        $second = $this->refunds->refund($subscription->id, CarbonImmutable::parse('2026-01-05'), RefundPolicy::Full);

        $this->assertSame(80_000, $second->refund_amount_minor);
        $this->assertSame(1, RevenueAllocation::where('kind', AllocationKind::Reversal)->count());
        $this->assertSame(1, LedgerEntry::where('type', LedgerEntryType::Clawback)->count());
        $this->assertMoneyConserved($subscription);
    }

    public function test_a_refund_before_the_term_starts_returns_everything(): void
    {
        $instructor = User::factory()->instructor()->create();
        $subscription = $this->subscription(SubscriptionPlan::Monthly, '2026-02-01', 28_000, [$instructor, 1]);

        $refunded = $this->refunds->refund($subscription->id, CarbonImmutable::parse('2026-01-25'));
        $this->allocator->allocateThrough($subscription->id, CarbonImmutable::parse('2026-03-01'));

        $this->assertSame(28_000, $refunded->refund_amount_minor);
        $this->assertSame(0, LedgerEntry::count());
        $this->assertMoneyConserved($subscription);
    }

    public function test_a_fully_served_term_cannot_be_refunded_pro_rata(): void
    {
        $subscription = $this->subscription(SubscriptionPlan::Monthly, '2026-01-01', 31_000);

        $this->expectException(DomainException::class);

        $this->refunds->refund($subscription->id, CarbonImmutable::parse('2026-02-01'));
    }

    public function test_the_refund_command_reports_the_amount_returned(): void
    {
        $subscription = $this->subscription(SubscriptionPlan::Quarterly, '2026-01-01', 90_000);

        $this->artisan('subscriptions:refund', ['subscription' => $subscription->id, '--on' => '2026-01-11'])
            ->expectsOutputToContain('refunded 80,000 piastres; service ended 2026-01-10')
            ->assertSuccessful();
    }
}
