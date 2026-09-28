<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed a reproducible demo platform, in business order:
     * accounts, catalogue, subscriptions, earnings up to a month ago, a
     * historic payout run, earnings up to yesterday (now outstanding for a
     * live payout demo), today's refunds, then a balance integrity rebuild.
     *
     * Model events stay enabled: subscriptions and the ledger rely on them.
     */
    public function run(): void
    {
        fake()->seed(2026);
        mt_srand(2026);
        $today = CarbonImmutable::today();

        $this->call([
            UserSeeder::class,
            CourseSeeder::class,
            SubscriptionSeeder::class,
        ]);

        $this->call(RevenueAllocationSeeder::class, parameters: ['through' => $today->subDays(31)->toDateString()]);
        $this->call(PayoutSeeder::class, parameters: ['on' => $today->subDays(30)->toDateString()]);
        $this->call(RevenueAllocationSeeder::class, parameters: ['through' => $today->subDay()->toDateString()]);
        $this->call(RefundSeeder::class, parameters: ['on' => $today->toDateString()]);
        $this->call(InstructorBalanceSeeder::class);
    }
}
