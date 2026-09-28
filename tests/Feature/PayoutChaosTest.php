<?php

namespace Tests\Feature;

use App\Enums\PayoutStatus;
use App\Jobs\SendPayoutJob;
use App\Models\Payout;
use App\Models\User;
use App\Services\Payments\MockPaymentProvider;
use App\Services\Payments\PaymentProvider;
use App\Services\PayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLedgerScenarios;
use Tests\TestCase;

/**
 * Random provider behaviour, overlapping runs, duplicate jobs and new earnings
 * arriving between runs. Whatever happens, the money that actually left the
 * platform for each instructor must equal what the ledger says was paid, and
 * never exceed what they earned.
 */
class PayoutChaosTest extends TestCase
{
    use BuildsLedgerScenarios, RefreshDatabase;

    public function test_unreliable_provider_and_repeated_runs_never_pay_anyone_more_than_they_earned(): void
    {
        config([
            'ledger.minimum_payout_minor' => 1_000,
            'ledger.mock_provider.weights' => [
                'success' => 40,
                'permanent_failure' => 20,
                'timeout_after_success' => 25,
                'timeout_before_success' => 15,
            ],
        ]);

        /** @var MockPaymentProvider $provider */
        $provider = app(PaymentProvider::class);
        $payouts = app(PayoutService::class);
        $instructors = User::factory()->instructor()->count(15)->create();

        for ($round = 1; $round <= 8; $round++) {
            foreach ($instructors as $instructor) {
                $this->earn($instructor, random_int(1, 40_000));
            }

            $this->artisan('payouts:run')->assertSuccessful();
            $this->artisan('payouts:run')->assertSuccessful();

            // Duplicate deliveries of every job, as a flaky queue might do.
            Payout::pluck('id')->each(fn (int $id) => (new SendPayoutJob($id))->handle($payouts));

            $this->travel(20)->minutes();
            $this->artisan('payouts:reconcile')->assertSuccessful();
            $this->travel(20)->minutes();
            $this->artisan('payouts:reconcile')->assertSuccessful();
        }

        $this->assertSame(0, Payout::whereIn('status', [PayoutStatus::Pending, PayoutStatus::Processing, PayoutStatus::Unknown])->count());
        $this->assertGreaterThan(0, Payout::where('status', PayoutStatus::Failed)->count(), 'Chaos should have produced failures.');

        foreach (Payout::all() as $payout) {
            $this->assertSame(1, $provider->transferCalls($payout->idempotency_key), "Payout {$payout->id} was sent more than once.");
            $expectedMoved = $payout->status === PayoutStatus::Succeeded ? $payout->amount_minor : 0;
            $this->assertSame($expectedMoved, $provider->movedMinor($payout->idempotency_key));
        }

        foreach ($instructors as $instructor) {
            $movedMinor = Payout::whereBelongsTo($instructor, 'instructor')->get()
                ->sum(fn (Payout $payout) => $provider->movedMinor($payout->idempotency_key));
            $balance = $this->balance($instructor);

            $this->assertSame($balance->paid_minor, $movedMinor);
            $this->assertSame(0, $balance->reserved_minor);
            $this->assertLessThanOrEqual($balance->earned_minor, $movedMinor);
            $this->assertSame($balance->earned_minor, $balance->paid_minor + $balance->outstandingMinor());
            $this->assertBalanceMatchesLedger($instructor);
        }
    }
}
