<?php

namespace Database\Seeders;

use App\Enums\MockTransferOutcome;
use App\Enums\PayoutRunStatus;
use App\Enums\PayoutStatus;
use App\Models\InstructorBalance;
use App\Models\Payout;
use App\Models\PayoutRun;
use App\Services\Payments\MockPaymentProvider;
use App\Services\Payments\PaymentProvider;
use App\Services\PayoutService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * A historic payout run on the given day, sent through the real payout
 * service and the mock provider, then reconciled an hour later so its
 * history shows succeeded and failed payouts. Provider outcomes are drawn
 * from the seeded RNG, so the result is reproducible.
 */
class PayoutSeeder extends Seeder
{
    public function run(PayoutService $payouts, PaymentProvider $provider, ?string $on = null): void
    {
        $runAt = ($on ? CarbonImmutable::parse($on) : CarbonImmutable::today())->setTime(9, 0);

        if ($provider instanceof MockPaymentProvider) {
            $provider->willRespond(...array_map(
                fn () => $this->pickOutcome(),
                range(1, max(1, InstructorBalance::count())),
            ));
        }

        try {
            Carbon::setTestNow($runAt);

            $run = PayoutRun::create(['status' => PayoutRunStatus::Running, 'started_at' => now()]);
            $payouts->reservePayouts($run, function (Payout $payout) use ($run, $payouts) {
                $run->increment('payouts_count');
                $run->increment('total_minor', $payout->amount_minor);
                $payouts->send($payout->id);
            });
            $run->update(['status' => PayoutRunStatus::Completed, 'finished_at' => now()]);

            Carbon::setTestNow($runAt->addHour());
            $payouts->markStaleProcessingAsUnknown();
            Payout::where('status', PayoutStatus::Unknown)->pluck('id')->each(fn (int $id) => $payouts->resolve($id));
        } finally {
            Carbon::setTestNow();
        }
    }

    private function pickOutcome(): MockTransferOutcome
    {
        $roll = mt_rand(1, 100);

        return match (true) {
            $roll <= 70 => MockTransferOutcome::Success,
            $roll <= 80 => MockTransferOutcome::PermanentFailure,
            $roll <= 92 => MockTransferOutcome::TimeoutAfterSuccess,
            default => MockTransferOutcome::TimeoutBeforeSuccess,
        };
    }
}
