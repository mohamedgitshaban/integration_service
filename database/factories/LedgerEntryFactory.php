<?php

namespace Database\Factories;

use App\Enums\LedgerEntryType;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Inserts ledger rows WITHOUT updating instructor_balances, so the cached
 * balance will not match. Use it only for display or query tests; anything
 * about money flows must go through LedgerService (see the `earn()` test
 * helper), and InstructorBalanceSeeder can rebuild balances afterwards.
 *
 * @extends Factory<LedgerEntry>
 */
class LedgerEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'instructor_id' => User::factory()->instructor(),
            'type' => LedgerEntryType::Earning,
            'amount_minor' => fake()->numberBetween(100, 50_000),
            'currency' => 'EGP',
            'idempotency_key' => 'factory:'.Str::uuid(),
            'occurred_on' => fake()->dateTimeBetween('-3 months'),
        ];
    }

    public function clawback(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => LedgerEntryType::Clawback,
            'amount_minor' => -abs($attributes['amount_minor']),
        ]);
    }
}
