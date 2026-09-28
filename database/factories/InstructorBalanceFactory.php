<?php

namespace Database\Factories;

use App\Models\InstructorBalance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A balance row with no ledger behind it. For display tests only; real
 * balances are written by LedgerService or rebuilt by InstructorBalanceSeeder.
 *
 * @extends Factory<InstructorBalance>
 */
class InstructorBalanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $earnedMinor = fake()->numberBetween(10_000, 500_000);
        $paidMinor = fake()->numberBetween(0, $earnedMinor);

        return [
            'instructor_id' => User::factory()->instructor(),
            'earned_minor' => $earnedMinor,
            'reserved_minor' => 0,
            'paid_minor' => $paidMinor,
        ];
    }
}
