<?php

namespace Database\Factories;

use App\Enums\AllocationKind;
use App\Models\RevenueAllocation;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds allocation rows for display and query tests. It writes no ledger
 * entries; real allocations come from RevenueAllocationService.
 *
 * @extends Factory<RevenueAllocation>
 */
class RevenueAllocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $grossMinor = fake()->numberBetween(1_000, 100_000);
        $platformMinor = intdiv($grossMinor * 3_000, 10_000);
        $start = fake()->dateTimeBetween('-3 months', '-1 month');

        return [
            'subscription_id' => Subscription::factory(),
            'kind' => AllocationKind::Recognition,
            'period_start' => $start,
            'period_end' => (clone $start)->modify('+6 days'),
            'gross_minor' => $grossMinor,
            'platform_minor' => $platformMinor,
            'instructor_pool_minor' => $grossMinor - $platformMinor,
        ];
    }

    public function reversal(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => AllocationKind::Reversal,
        ]);
    }
}
