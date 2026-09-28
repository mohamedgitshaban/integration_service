<?php

namespace Database\Factories;

use App\Enums\PayoutRunStatus;
use App\Models\PayoutRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayoutRun>
 */
class PayoutRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = fake()->dateTimeBetween('-2 months');

        return [
            'status' => PayoutRunStatus::Completed,
            'payouts_count' => 0,
            'total_minor' => 0,
            'started_at' => $startedAt,
            'finished_at' => (clone $startedAt)->modify('+2 minutes'),
        ];
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PayoutRunStatus::Running,
            'finished_at' => null,
        ]);
    }
}
