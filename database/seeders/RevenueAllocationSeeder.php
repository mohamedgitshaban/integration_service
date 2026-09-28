<?php

namespace Database\Seeders;

use App\Models\Subscription;
use App\Services\RevenueAllocationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Recognises served days through the given date (default: yesterday) using
 * the real allocation service, producing allocations, earning ledger entries
 * and instructor balances.
 */
class RevenueAllocationSeeder extends Seeder
{
    public function run(RevenueAllocationService $allocator, ?string $through = null): void
    {
        $throughDay = $through ? CarbonImmutable::parse($through) : CarbonImmutable::yesterday();

        Subscription::query()
            ->dueForRecognition($throughDay)
            ->select('id')
            ->chunkById(500, function ($subscriptions) use ($allocator, $throughDay) {
                foreach ($subscriptions as $subscription) {
                    $allocator->allocateThrough($subscription->id, $throughDay);
                }
            });
    }
}
