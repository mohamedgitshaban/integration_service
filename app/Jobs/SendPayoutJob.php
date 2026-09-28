<?php

namespace App\Jobs;

use App\Services\PayoutService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends one payout. Uniqueness only saves duplicate queue entries; the
 * guarantee against double-paying is the atomic claim in PayoutService::send,
 * which makes every duplicate, retry or re-dispatch a no-op.
 */
class SendPayoutJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public int $uniqueFor = 3600;

    public function __construct(public int $payoutId) {}

    public function uniqueId(): string
    {
        return (string) $this->payoutId;
    }

    public function handle(PayoutService $payouts): void
    {
        $payouts->send($this->payoutId);
    }
}
