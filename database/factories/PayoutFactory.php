<?php

namespace Database\Factories;

use App\Enums\PayoutStatus;
use App\Models\Payout;
use App\Models\PayoutRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A payout row with no ledger reservation behind it. For display tests
 * (e.g. payout history screens) only; real payouts are created by
 * PayoutService::reserveFor, which also debits the ledger.
 *
 * @extends Factory<Payout>
 */
class PayoutFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payout_run_id' => PayoutRun::factory(),
            'instructor_id' => User::factory()->instructor(),
            'amount_minor' => fake()->numberBetween(10_000, 300_000),
            'currency' => 'EGP',
            'destination_account' => fake()->numerify('EG##########################'),
            'status' => PayoutStatus::Pending,
            'idempotency_key' => 'payout-'.Str::uuid(),
            'attempts' => 0,
        ];
    }

    public function succeeded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PayoutStatus::Succeeded,
            'provider_reference' => 'MOCK-'.Str::upper(Str::random(12)),
            'attempts' => 1,
            'sent_at' => now()->subMinutes(5),
            'settled_at' => now()->subMinutes(5),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PayoutStatus::Failed,
            'attempts' => 1,
            'last_error' => 'Beneficiary account closed.',
            'sent_at' => now()->subMinutes(5),
            'settled_at' => now()->subMinutes(5),
        ]);
    }

    public function unknown(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PayoutStatus::Unknown,
            'attempts' => 1,
            'last_error' => 'Provider did not respond in time; transfer outcome unknown.',
            'sent_at' => now()->subMinutes(5),
        ]);
    }
}
