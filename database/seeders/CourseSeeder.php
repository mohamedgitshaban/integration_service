<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Three to six courses for every instructor.
 */
class CourseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->where('role', 'instructor')->orderBy('id')->each(
            fn (User $instructor) => Course::factory()
                ->count(fake()->numberBetween(3, 6))
                ->for($instructor, 'instructor')
                ->create()
        );
    }
}
