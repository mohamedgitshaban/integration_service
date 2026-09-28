<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_student_can_register_and_receives_a_token(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'Mona',
            'email' => 'mona@example.com',
            'password' => 'secret-password-1',
            'password_confirmation' => 'secret-password-1',
            'role' => 'student',
        ]);

        $response->assertCreated()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.role', 'student')
            ->assertJsonMissingPath('user.password');
        $this->assertNotEmpty($response->json('token'));

        $user = User::firstWhere('email', 'mona@example.com');
        $this->assertNotSame('secret-password-1', $user->password, 'Password must be stored hashed.');
        $this->assertTrue(Hash::check('secret-password-1', $user->password));
    }

    public function test_nobody_can_register_as_an_admin(): void
    {
        $this->postJson('/api/v1/register', [
            'name' => 'Mallory',
            'email' => 'mallory@example.com',
            'password' => 'secret-password-1',
            'password_confirmation' => 'secret-password-1',
            'role' => 'admin',
        ])->assertUnprocessable()->assertJsonValidationErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'mallory@example.com']);
    }

    public function test_login_returns_a_token_for_valid_credentials_only(): void
    {
        $user = User::factory()->instructor()->create(['password' => 'correct-horse']);

        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'correct-horse'])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.role', 'instructor');
    }

    public function test_me_returns_the_authenticated_user_and_logout_revokes_the_token(): void
    {
        $user = User::factory()->student()->create(['password' => 'correct-horse']);
        $token = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'correct-horse'])->json('token');

        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.email', $user->email);
        $this->withToken($token)->postJson('/api/v1/logout')->assertNoContent();

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_protected_endpoints_require_a_token(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->getJson('/api/v1/instructor/balance')->assertUnauthorized();
        $this->getJson('/api/v1/student/subscriptions')->assertUnauthorized();
    }

    public function test_each_role_is_kept_to_its_own_endpoints(): void
    {
        Sanctum::actingAs(User::factory()->student()->create());
        $this->getJson('/api/v1/instructor/balance')->assertForbidden();
        $this->postJson('/api/v1/instructor/withdrawals')->assertForbidden();

        Sanctum::actingAs(User::factory()->instructor()->create());
        $this->getJson('/api/v1/student/subscriptions')->assertForbidden();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/v1/instructor/balance')->assertForbidden();
        $this->getJson('/api/v1/student/subscriptions')->assertForbidden();
    }
}
