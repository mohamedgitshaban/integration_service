<?php

namespace Tests\Feature;

use App\Enums\LedgerEntryType;
use App\Enums\MockTransferOutcome;
use App\Enums\PayoutRunStatus;
use App\Enums\PayoutStatus;
use App\Enums\RefundPolicy;
use App\Enums\SubscriptionPlan;
use App\Jobs\SendPayoutJob;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\PayoutRun;
use App\Models\User;
use App\Services\Payments\MockPaymentProvider;
use App\Services\Payments\PaymentProvider;
use App\Services\PayoutService;
use App\Services\RefundService;
use App\Services\RevenueAllocationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\BuildsLedgerScenarios;
use Tests\TestCase;

class PayoutTest extends TestCase
{
    use BuildsLedgerScenarios, RefreshDatabase;

    private MockPaymentProvider $provider;

    private PayoutService $payouts;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ledger.minimum_payout_minor' => 1_000,
            'ledger.stale_processing_minutes' => 15,
            'ledger.not_found_grace_minutes' => 30,
            'ledger.mock_provider.confirmation_delay_seconds' => 120,
        ]);

        $this->provider = app(PaymentProvider::class);
        $this->payouts = app(PayoutService::class);
    }

    public function test_a_successful_payout_pays_the_outstanding_balance_once(): void
    {
        $instructor = $this->instructorOwed(25_000);
        $this->provider->willRespond(MockTransferOutcome::Success);

        $this->artisan('payouts:run')->expectsOutputToContain('queued 1 payout(s) totalling 25,000 piastres')->assertSuccessful();

        $payout = Payout::sole();
        $this->assertSame(PayoutStatus::Succeeded, $payout->status);
        $this->assertNotNull($payout->provider_reference);
        $this->assertSame(25_000, $this->provider->movedMinor($payout->idempotency_key));
        $this->assertSame(25_000, $this->balance($instructor)->paid_minor);
        $this->assertSame(0, $this->balance($instructor)->outstandingMinor());
        $this->assertSame(PayoutRunStatus::Completed, PayoutRun::sole()->status);
        $this->assertBalanceMatchesLedger($instructor);
    }

    public function test_running_payouts_twice_never_double_pays(): void
    {
        $instructor = $this->instructorOwed(25_000);
        $this->provider->willRespond(MockTransferOutcome::Success, MockTransferOutcome::Success);

        $this->artisan('payouts:run')->assertSuccessful();
        $this->artisan('payouts:run')->expectsOutputToContain('queued 0 payout(s)')->assertSuccessful();

        $payout = Payout::sole();
        $this->assertSame(1, $this->provider->transferCalls($payout->idempotency_key));
        $this->assertSame(25_000, $this->balance($instructor)->paid_minor);
    }

    public function test_an_overlapping_run_finds_nothing_left_to_reserve(): void
    {
        $instructor = $this->instructorOwed(25_000);
        $run = PayoutRun::create(['status' => PayoutRunStatus::Running, 'started_at' => now()]);

        $first = $this->payouts->reserveFor($instructor->id, $run, 1_000);
        $second = $this->payouts->reserveFor($instructor->id, $run, 1_000);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(25_000, $this->balance($instructor)->reserved_minor);
        $this->assertSame(0, $this->balance($instructor)->outstandingMinor());
    }

    public function test_a_run_that_starts_while_another_holds_the_lock_does_nothing(): void
    {
        $this->instructorOwed(25_000);
        $lock = Cache::lock('payouts:run', 60);
        $lock->get();

        $this->artisan('payouts:run')->expectsOutputToContain('Another payout run is in progress')->assertSuccessful();

        $this->assertSame(0, Payout::count());
        $lock->release();
    }

    public function test_duplicate_and_retried_send_jobs_never_call_the_provider_twice(): void
    {
        $payout = $this->reservedPayout($this->instructorOwed(25_000));
        $this->provider->willRespond(MockTransferOutcome::Success, MockTransferOutcome::Success, MockTransferOutcome::Success);

        // Bypass queue uniqueness on purpose: the claim alone must make repeats harmless.
        (new SendPayoutJob($payout->id))->handle($this->payouts);
        (new SendPayoutJob($payout->id))->handle($this->payouts);
        (new SendPayoutJob($payout->id))->handle($this->payouts);

        $this->assertSame(1, $this->provider->transferCalls($payout->idempotency_key));
        $this->assertSame(PayoutStatus::Succeeded, $payout->fresh()->status);
        $this->assertSame(1, $payout->fresh()->attempts);
    }

    public function test_a_permanent_failure_returns_the_money_to_outstanding_and_the_next_run_pays_it(): void
    {
        $instructor = $this->instructorOwed(25_000);
        $this->provider->willRespond(MockTransferOutcome::PermanentFailure, MockTransferOutcome::Success);

        $this->artisan('payouts:run')->assertSuccessful();

        $failed = Payout::sole();
        $this->assertSame(PayoutStatus::Failed, $failed->status);
        $this->assertSame(25_000, $this->balance($instructor)->outstandingMinor());
        $this->assertSame(1, LedgerEntry::where('type', LedgerEntryType::PayoutReversal)->count());
        $this->assertBalanceMatchesLedger($instructor);

        $this->artisan('payouts:run')->assertSuccessful();

        $retry = Payout::where('status', PayoutStatus::Succeeded)->sole();
        $this->assertNotSame($failed->idempotency_key, $retry->idempotency_key);
        $this->assertSame(0, $this->provider->movedMinor($failed->idempotency_key));
        $this->assertSame(25_000, $this->provider->movedMinor($retry->idempotency_key));
        $this->assertSame(25_000, $this->balance($instructor)->paid_minor);
        $this->assertBalanceMatchesLedger($instructor);
    }

    public function test_a_timeout_after_the_money_moved_is_never_resent_and_is_confirmed_by_a_status_check(): void
    {
        $instructor = $this->instructorOwed(25_000);
        $this->provider->willRespond(MockTransferOutcome::TimeoutAfterSuccess);

        $this->artisan('payouts:run')->assertSuccessful();
        $payout = Payout::sole();
        $this->assertSame(PayoutStatus::Unknown, $payout->status);
        $this->assertSame(25_000, $this->balance($instructor)->reserved_minor);

        // A new run must not pay the in-flight amount again.
        $this->artisan('payouts:run')->expectsOutputToContain('queued 0 payout(s)')->assertSuccessful();

        // The provider has not made the transfer visible yet: stay unknown, do not fail it.
        $this->artisan('payouts:reconcile')->assertSuccessful();
        $this->assertSame(PayoutStatus::Unknown, $payout->fresh()->status);

        $this->travel(5)->minutes();
        $this->artisan('payouts:reconcile')->assertSuccessful();

        $this->assertSame(PayoutStatus::Succeeded, $payout->fresh()->status);
        $this->assertSame(1, $this->provider->transferCalls($payout->idempotency_key));
        $this->assertSame(25_000, $this->provider->movedMinor($payout->idempotency_key));
        $this->assertSame(25_000, $this->balance($instructor)->paid_minor);
        $this->assertSame(0, $this->balance($instructor)->reserved_minor);
    }

    public function test_a_timeout_before_the_money_moved_fails_only_after_the_grace_period_and_is_then_repaid(): void
    {
        $instructor = $this->instructorOwed(25_000);
        $this->provider->willRespond(MockTransferOutcome::TimeoutBeforeSuccess, MockTransferOutcome::Success);

        $this->artisan('payouts:run')->assertSuccessful();
        $lost = Payout::sole();

        $this->travel(10)->minutes();
        $this->artisan('payouts:reconcile')->assertSuccessful();
        $this->assertSame(PayoutStatus::Unknown, $lost->fresh()->status);

        $this->travel(25)->minutes();
        $this->artisan('payouts:reconcile')->assertSuccessful();
        $this->assertSame(PayoutStatus::Failed, $lost->fresh()->status);
        $this->assertSame(25_000, $this->balance($instructor)->outstandingMinor());

        $this->artisan('payouts:run')->assertSuccessful();

        $this->assertSame(1, $this->provider->transferCalls($lost->idempotency_key));
        $this->assertSame(0, $this->provider->movedMinor($lost->idempotency_key));
        $this->assertSame(25_000, $this->balance($instructor)->paid_minor);
        $this->assertBalanceMatchesLedger($instructor);
    }

    public function test_a_worker_that_dies_mid_call_is_resolved_by_status_check_not_resent(): void
    {
        $instructor = $this->instructorOwed(25_000);
        $payout = $this->reservedPayout($instructor);

        // The worker claims the payout, the provider moves the money, then the process dies
        // before recording anything.
        Payout::whereKey($payout->id)->update(['status' => PayoutStatus::Processing, 'sent_at' => now(), 'attempts' => 1]);
        $this->provider->willRespond(MockTransferOutcome::Success)
            ->transfer($payout->idempotency_key, 'EG00', $payout->amount_minor, 'EGP');

        // The queue retries the job: it cannot claim a processing payout.
        (new SendPayoutJob($payout->id))->handle($this->payouts);
        $this->assertSame(PayoutStatus::Processing, $payout->fresh()->status);

        $this->travel(16)->minutes();
        $this->artisan('payouts:reconcile')->expectsOutputToContain('marked 1 stale processing as unknown')->assertSuccessful();

        $this->assertSame(PayoutStatus::Succeeded, $payout->fresh()->status);
        $this->assertSame(1, $this->provider->transferCalls($payout->idempotency_key));
        $this->assertSame(25_000, $this->balance($instructor)->paid_minor);
    }

    public function test_a_pending_payout_whose_job_was_lost_is_sent_by_reconcile(): void
    {
        $instructor = $this->instructorOwed(25_000);
        $payout = $this->reservedPayout($instructor);
        $this->provider->willRespond(MockTransferOutcome::Success);

        $this->travel(16)->minutes();
        $this->artisan('payouts:reconcile')->expectsOutputToContain('Re-queued 1 pending')->assertSuccessful();

        $this->assertSame(PayoutStatus::Succeeded, $payout->fresh()->status);
        $this->assertSame(25_000, $this->balance($instructor)->paid_minor);
    }

    public function test_instructors_below_the_minimum_or_without_payout_details_are_skipped(): void
    {
        $belowMinimum = $this->instructorOwed(999);
        $noBankAccount = User::factory()->instructor()->create(['bank_account_number' => null]);
        $this->earn($noBankAccount, 50_000);

        $this->artisan('payouts:run')->expectsOutputToContain('queued 0 payout(s)')->assertSuccessful();

        $this->assertSame(999, $this->balance($belowMinimum)->outstandingMinor());
        $this->assertSame(50_000, $this->balance($noBankAccount)->outstandingMinor());
    }

    public function test_a_refund_after_payout_leaves_a_debt_that_is_recovered_from_future_earnings(): void
    {
        config(['ledger.platform_share_bps' => 3_000]);
        $allocator = app(RevenueAllocationService::class);
        $instructor = User::factory()->instructor()->create();
        $refunded = $this->subscription(SubscriptionPlan::Quarterly, '2026-01-01', 90_000, [$instructor, 1]);
        $later = $this->subscription(SubscriptionPlan::Quarterly, '2026-01-01', 90_000, [$instructor, 1]);
        $this->provider->willRespond(MockTransferOutcome::Success, MockTransferOutcome::Success);

        $allocator->allocateThrough($refunded->id, CarbonImmutable::parse('2026-01-30'));
        $this->artisan('payouts:run')->assertSuccessful();
        $this->assertSame(21_000, $this->balance($instructor)->paid_minor);

        app(RefundService::class)->refund($refunded->id, CarbonImmutable::parse('2026-01-31'), RefundPolicy::Full);
        $this->assertSame(-21_000, $this->balance($instructor)->outstandingMinor());

        $this->artisan('payouts:run')->expectsOutputToContain('queued 0 payout(s)')->assertSuccessful();

        $allocator->allocateThrough($later->id, CarbonImmutable::parse('2026-02-14'));
        $this->assertSame(31_500 - 21_000, $this->balance($instructor)->outstandingMinor());

        $this->artisan('payouts:run')->expectsOutputToContain('totalling 10,500 piastres')->assertSuccessful();
        $this->assertSame(31_500, $this->balance($instructor)->paid_minor);
        $this->assertSame(31_500, $this->balance($instructor)->earned_minor);
        $this->assertBalanceMatchesLedger($instructor);
    }

    private function instructorOwed(int $amountMinor): User
    {
        $instructor = User::factory()->instructor()->create();
        $this->earn($instructor, $amountMinor);

        return $instructor;
    }

    private function reservedPayout(User $instructor): Payout
    {
        $run = PayoutRun::create(['status' => PayoutRunStatus::Running, 'started_at' => now()]);

        return $this->payouts->reserveFor($instructor->id, $run, 1_000);
    }
}
