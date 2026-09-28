<?php

namespace Database\Seeders;

use App\Enums\RefundPolicy;
use App\Models\Subscription;
use App\Services\RefundService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Refunds about 5% of subscriptions that are mid-term on the given day:
 * mostly pro-rata, every fourth one a full refund (which claws back earnings,
 * possibly after they were paid out). The demo student is never refunded.
 */
class RefundSeeder extends Seeder
{
    public function run(RefundService $refunds, ?string $on = null): void
    {
        $effectiveOn = $on ? CarbonImmutable::parse($on) : CarbonImmutable::today();

        $candidates = Subscription::query()
            ->where('starts_on', '<', $effectiveOn)
            ->where('ends_on', '>', $effectiveOn)
            ->whereHas('student', fn ($query) => $query->where('email', '!=', 'student@example.com'))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $chosen = fake()->randomElements($candidates, min(count($candidates), max(1, intdiv(Subscription::count(), 20))));

        foreach (array_values($chosen) as $index => $subscriptionId) {
            $refunds->refund($subscriptionId, $effectiveOn, $index % 4 === 0 ? RefundPolicy::Full : RefundPolicy::ProRata);
        }
    }
}
