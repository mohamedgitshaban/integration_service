<?php

namespace Tests\Feature\Api;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_plans_list_prices_from_config(): void
    {
        config(['ledger.plan_prices_minor.annual' => 123_400]);

        $this->getJson('/api/v1/plans')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonFragment(['plan' => 'annual', 'months' => 12, 'price_minor' => 123_400, 'currency' => 'EGP']);
    }

    public function test_courses_are_public_searchable_and_show_their_instructor(): void
    {
        $instructor = User::factory()->instructor()->create(['name' => 'Dr. Laila']);
        Course::factory()->for($instructor, 'instructor')->create(['title' => 'Intro to Laravel']);
        Course::factory()->create(['title' => 'Watercolour Basics']);

        $this->getJson('/api/v1/courses?search=Laravel')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Intro to Laravel')
            ->assertJsonPath('data.0.instructor.name', 'Dr. Laila');
    }
}
