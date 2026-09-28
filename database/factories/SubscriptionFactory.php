<?php

namespace Database\Factories;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'amount_minor' => 29_900,
            'currency' => 'EGP',
            'status' => SubscriptionStatus::Active,
            ...$this->termAttributes(SubscriptionPlan::Monthly, CarbonImmutable::today()),
        ];
    }

    /**
     * Set the plan and start day; the end day and term length are derived.
     */
    public function plan(SubscriptionPlan $plan, ?CarbonImmutable $startsOn = null, ?int $amountMinor = null): static
    {
        return $this->state(fn (array $attributes) => array_filter([
            ...$this->termAttributes($plan, $startsOn ?? CarbonImmutable::today()),
            'amount_minor' => $amountMinor,
        ], fn ($value) => $value !== null));
    }

    /**
     * @return array{plan: SubscriptionPlan, starts_on: CarbonImmutable, ends_on: CarbonImmutable, term_days: int}
     */
    private function termAttributes(SubscriptionPlan $plan, CarbonImmutable $startsOn): array
    {
        return [
            'plan' => $plan,
            'starts_on' => $startsOn->startOfDay(),
            'ends_on' => $plan->endsOn($startsOn),
            'term_days' => $plan->termDays($startsOn),
        ];
    }
}
