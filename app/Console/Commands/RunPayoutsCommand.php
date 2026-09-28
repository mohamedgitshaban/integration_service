<?php

namespace App\Console\Commands;

use App\Enums\PayoutRunStatus;
use App\Jobs\SendPayoutJob;
use App\Models\Payout;
use App\Models\PayoutRun;
use App\Services\PayoutService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('payouts:run')]
#[Description('Reserve every instructor\'s outstanding balance and queue the transfers.')]
class RunPayoutsCommand extends Command
{
    /**
     * The cache lock only avoids wasted work when runs overlap. Correctness
     * does not depend on it: reservation happens under each instructor's
     * balance row lock, so a concurrent run finds nothing left to reserve.
     */
    public function handle(PayoutService $payouts): int
    {
        $lock = Cache::lock('payouts:run', 3600);

        if (! $lock->get()) {
            $this->warn('Another payout run is in progress; nothing to do.');

            return self::SUCCESS;
        }

        try {
            $run = PayoutRun::create(['status' => PayoutRunStatus::Running, 'started_at' => now()]);

            $payouts->reservePayouts($run, function (Payout $payout) use ($run) {
                $run->increment('payouts_count');
                $run->increment('total_minor', $payout->amount_minor);
                SendPayoutJob::dispatch($payout->id);
            });

            $run->update(['status' => PayoutRunStatus::Completed, 'finished_at' => now()]);
        } finally {
            $lock->release();
        }

        $this->info(sprintf('Payout run %d queued %d payout(s) totalling %s piastres.', $run->id, $run->payouts_count, number_format($run->total_minor)));

        return self::SUCCESS;
    }
}
