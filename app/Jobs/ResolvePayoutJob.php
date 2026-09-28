<?php

namespace App\Jobs;

use App\Services\PayoutService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Resolves one outcome-unknown payout by asking the provider for its status.
 * Never re-sends money. Payouts that stay unknown are picked up again by the
 * next reconcile run.
 */
class ResolvePayoutJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public int $uniqueFor = 600;

    public function __construct(public int $payoutId) {}

    public function uniqueId(): string
    {
        return (string) $this->payoutId;
    }

    public function handle(PayoutService $payouts): void
    {
        $payouts->resolve($this->payoutId);
    }
}
