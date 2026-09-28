<?php

namespace Tests\Feature\Api;

use App\Enums\MockTransferOutcome;
use App\Enums\PayoutStatus;
use App\Jobs\SendPayoutJob;
use App\Models\Course;
use App\Models\Payout;
use App\Models\User;
use App\Services\Payments\MockPaymentProvider;
use App\Services\Payments\PaymentProvider;
use App\Services\PayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLedgerScenarios;
use Tests\TestCase;

class WithdrawalApiTest extends TestCase
{
    use BuildsLedgerScenarios, RefreshDatabase;

    private MockPaymentProvider $provider;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ledger.minimum_payout_minor' => 10_000, 'ledger.platform_share_bps' => 3_000]);
        $this->provider = app(PaymentProvider::class);
        $this->instructor = User::factory()->instructor()->create(['bank_account_number' => 'EG111111111111111111']);
    }

    public function test_an_instructor_withdraws_their_whole_outstanding_balance(): void
    {
        $this->earn($this->instructor, 25_000);
        $this->provider->willRespond(MockTransferOutcome::Success);
        Sanctum::actingAs($this->instructor);

        $this->postJson('/api/v1/instructor/withdrawals')
            ->assertAccepted()
            ->assertJsonPath('data.type', 'withdrawal')
            ->assertJsonPath('data.amount_minor', 25_000)
            ->assertJsonPath('data.status', 'succeeded');

        $this->getJson('/api/v1/instructor/balance')
            ->assertJsonPath('data.paid_minor', 25_000)
            ->assertJsonPath('data.outstanding_minor', 0);
    }

    public function test_repeating_a_withdrawal_with_the_same_idempotency_key_returns_the_same_payout(): void
    {
        $this->earn($this->instructor, 25_000);
        $this->provider->willRespond(MockTransferOutcome::Success, MockTransferOutcome::Success);
        Sanctum::actingAs($this->instructor);

        $first = $this->withHeader('Idempotency-Key', 'tap-1')->postJson('/api/v1/instructor/withdrawals')->assertAccepted();
        $this->earn($this->instructor, 40_000);
        $again = $this->withHeader('Idempotency-Key', 'tap-1')->postJson('/api/v1/instructor/withdrawals')->assertOk();

        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $this->assertSame(25_000, $again->json('data.amount_minor'));
        $this->assertSame(1, Payout::count());
        $this->assertSame(1, $this->provider->transferCalls(Payout::sole()->idempotency_key));
    }

    public function test_a_second_withdrawal_without_a_key_finds_nothing_left_to_withdraw(): void
    {
        $this->earn($this->instructor, 25_000);
        Queue::fake();
        Sanctum::actingAs($this->instructor);

        $this->postJson('/api/v1/instructor/withdrawals')->assertAccepted();
        $this->postJson('/api/v1/instructor/withdrawals')->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->assertSame(1, Payout::count());
        Queue::assertPushed(SendPayoutJob::class, 1);
    }

    public function test_a_withdrawal_and_the_scheduled_run_never_pay_the_same_money_twice(): void
    {
        $this->earn($this->instructor, 25_000);
        $this->provider->willRespond(MockTransferOutcome::TimeoutAfterSuccess);
        Sanctum::actingAs($this->instructor);

        $this->postJson('/api/v1/instructor/withdrawals')->assertAccepted()->assertJsonPath('data.status', 'unknown');
        $this->artisan('payouts:run')->expectsOutputToContain('queued 0 payout(s)')->assertSuccessful();

        $this->assertSame(1, Payout::count());
    }

    public function test_withdrawals_below_the_minimum_or_without_payout_details_are_rejected(): void
    {
        $this->earn($this->instructor, 9_999);
        Sanctum::actingAs($this->instructor);
        $this->postJson('/api/v1/instructor/withdrawals')->assertUnprocessable()->assertJsonValidationErrors('amount');

        $noBank = User::factory()->instructor()->create(['bank_account_number' => null]);
        $this->earn($noBank, 50_000);
        Sanctum::actingAs($noBank);
        $this->postJson('/api/v1/instructor/withdrawals')->assertUnprocessable()->assertJsonValidationErrors('bank_account_number');

        $this->assertSame(0, Payout::count());
    }

    public function test_changing_bank_details_does_not_redirect_a_payout_already_reserved(): void
    {
        $this->earn($this->instructor, 25_000);
        Queue::fake();
        Sanctum::actingAs($this->instructor);

        $this->postJson('/api/v1/instructor/withdrawals')->assertAccepted();
        $this->putJson('/api/v1/instructor/payout-details', ['bank_account_number' => 'EG999999999999999999'])->assertOk();

        $payout = Payout::sole();
        $this->provider->willRespond(MockTransferOutcome::Success);
        app(PayoutService::class)->send($payout->id);

        $this->assertSame('EG111111111111111111', $this->provider->destinationFor($payout->idempotency_key));
    }

    public function test_full_scenario_student_pays_time_passes_instructor_withdraws(): void
    {
        $course = Course::factory()->for($this->instructor, 'instructor')->create();
        Sanctum::actingAs(User::factory()->student()->create());
        $this->postJson('/api/v1/student/subscriptions', [
            'plan' => 'quarterly',
            'course_ids' => [$course->id],
            'payment_reference' => 'pay_e2e',
        ])->assertCreated();

        $this->travel(31)->days();
        $this->artisan('revenue:allocate')->assertSuccessful();

        Sanctum::actingAs($this->instructor);
        $earned = $this->getJson('/api/v1/instructor/balance')->assertOk()->json('data.earned_minor');
        $this->assertGreaterThan(10_000, $earned);

        $this->provider->willRespond(MockTransferOutcome::Success);
        $this->withHeader('Idempotency-Key', 'e2e')->postJson('/api/v1/instructor/withdrawals')
            ->assertAccepted()
            ->assertJsonPath('data.amount_minor', $earned)
            ->assertJsonPath('data.status', PayoutStatus::Succeeded->value);

        $this->getJson('/api/v1/instructor/balance')
            ->assertJsonPath('data.paid_minor', $earned)
            ->assertJsonPath('data.outstanding_minor', 0);
        $this->assertBalanceMatchesLedger($this->instructor);
    }
}
