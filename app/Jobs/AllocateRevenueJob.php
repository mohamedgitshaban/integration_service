<?php

namespace App\Jobs;

use App\Services\RevenueAllocationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Recognises revenue for a chunk of subscriptions. Each subscription commits
 * in its own transaction, so a crash or retry only redoes what is unfinished;
 * already-recognised subscriptions are no-ops on the next attempt.
 */
class AllocateRevenueJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    /**
     * @param  list<int>  $subscriptionIds
     */
    public function __construct(
        public array $subscriptionIds,
        public string $throughDate,
    ) {}

    public function handle(RevenueAllocationService $allocator): void
    {
        $through = CarbonImmutable::parse($this->throughDate);
        $failedIds = [];

        foreach ($this->subscriptionIds as $subscriptionId) {
            try {
                $allocator->allocateThrough($subscriptionId, $through);
            } catch (Throwable $exception) {
                report($exception);
                $failedIds[] = $subscriptionId;
            }
        }

        if ($failedIds !== []) {
            throw new RuntimeException('Revenue allocation failed for subscriptions: '.implode(', ', $failedIds));
        }
    }
}
