<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Fixed login accounts (password: "password") plus bulk instructors and students.
 */
class UserSeeder extends Seeder
{
    public function __construct(
        private int $instructorCount = 25,
        private int $studentCount = 300,
    ) {}

    public function run(): void
    {
        User::factory()->admin()->create(['name' => 'Platform Admin', 'email' => 'admin@example.com']);
        User::factory()->instructor()->create(['name' => 'Demo Instructor', 'email' => 'instructor@example.com']);
        User::factory()->student()->create(['name' => 'Demo Student', 'email' => 'student@example.com']);

        // Owed money but cannot be paid: shows payouts skipping missing payout details.
        User::factory()->instructor()->create([
            'name' => 'Instructor Without Bank Details',
            'email' => 'no-bank@example.com',
            'bank_account_number' => null,
        ]);

        User::factory()->instructor()->count(max(0, $this->instructorCount - 2))->create();
        User::factory()->student()->count(max(0, $this->studentCount - 1))->create();
    }
}
