<?php

namespace App\Console\Commands;

use App\Enums\PayoutStatus;
use App\Jobs\ResolvePayoutJob;
use App\Jobs\SendPayoutJob;
use App\Models\Payout;
use App\Services\PayoutService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('payouts:reconcile')]
#[Description('Recover payouts left behind by crashes and resolve unknown outcomes via provider status checks.')]
class ReconcilePayoutsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PayoutService $payouts): int
    {
        $staleMinutes = (int) config('ledger.stale_processing_minutes');

        $requeued = 0;
        Payout::query()
            ->where('status', PayoutStatus::Pending)
            ->where('created_at', '<', now()->subMinutes($staleMinutes))
            ->select('id')
            ->chunkById(500, function ($pending) use (&$requeued) {
                foreach ($pending as $payout) {
                    SendPayoutJob::dispatch($payout->id);
                    $requeued++;
                }
            });

        $orphaned = $payouts->markStaleProcessingAsUnknown();

        $resolving = 0;
        Payout::query()
            ->where('status', PayoutStatus::Unknown)
            ->select('id')
            ->chunkById(500, function ($unknown) use (&$resolving) {
                foreach ($unknown as $payout) {
                    ResolvePayoutJob::dispatch($payout->id);
                    $resolving++;
                }
            });

        $this->info("Re-queued {$requeued} pending, marked {$orphaned} stale processing as unknown, checking {$resolving} unknown.");

        return self::SUCCESS;
    }
}
