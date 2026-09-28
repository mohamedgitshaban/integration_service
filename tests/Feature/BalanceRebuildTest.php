<?php

namespace Tests\Feature;

use App\Enums\MockTransferOutcome;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\Payments\PaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\BuildsLedgerScenarios;
use Tests\TestCase;

class BalanceRebuildTest extends TestCase
{
    use BuildsLedgerScenarios, RefreshDatabase;

    public function test_a_drifted_balance_is_rebuilt_from_the_ledger_and_payouts(): void
    {
        config(['ledger.minimum_payout_minor' => 1_000]);
        $instructor = User::factory()->instructor()->create();
        $this->earn($instructor, 30_000);
        app(PaymentProvider::class)->willRespond(MockTransferOutcome::Success, MockTransferOutcome::TimeoutAfterSuccess);
        $this->artisan('payouts:run');
        $this->earn($instructor, 12_000);
        $this->artisan('payouts:run');

        $expected = $this->balance($instructor)->only(['earned_minor', 'reserved_minor', 'paid_minor']);
        InstructorBalance::where('instructor_id', $instructor->id)->update(['earned_minor' => 1, 'reserved_minor' => 2, 'paid_minor' => 3]);

        DB::transaction(fn () => app(LedgerService::class)->rebuildBalance($instructor->id));

        $this->assertSame(['earned_minor' => 42_000, 'reserved_minor' => 12_000, 'paid_minor' => 30_000], $expected);
        $this->assertSame($expected, $this->balance($instructor)->only(['earned_minor', 'reserved_minor', 'paid_minor']));
    }

    public function test_a_ledger_that_disagrees_with_payouts_is_reported_not_papered_over(): void
    {
        $instructor = User::factory()->instructor()->create();
        LedgerEntry::factory()->for($instructor, 'instructor')->create(['type' => 'payout', 'amount_minor' => -5_000]);

        $this->expectException(LogicException::class);

        DB::transaction(fn () => app(LedgerService::class)->rebuildBalance($instructor->id));
    }
}
