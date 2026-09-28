<?php

namespace Tests\Feature\Api;

use App\Enums\SubscriptionPlan;
use App\Models\Course;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentApiTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->student()->create();
        Sanctum::actingAs($this->student);
    }

    public function test_a_student_subscribes_at_the_configured_price_whatever_the_client_sends(): void
    {
        $courses = Course::factory()->count(2)->create();

        $response = $this->postJson('/api/v1/student/subscriptions', [
            'plan' => 'quarterly',
            'course_ids' => $courses->pluck('id')->all(),
            'payment_reference' => 'pay_123',
            'amount_minor' => 1,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.plan', 'quarterly')
            ->assertJsonPath('data.amount_minor', 79_900)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.starts_on', CarbonImmutable::today()->toDateString())
            ->assertJsonCount(2, 'data.courses');
    }

    public function test_replaying_the_same_payment_returns_the_original_subscription(): void
    {
        $payload = ['plan' => 'monthly', 'course_ids' => [Course::factory()->create()->id], 'payment_reference' => 'pay_abc'];

        $first = $this->postJson('/api/v1/student/subscriptions', $payload)->assertCreated();
        $second = $this->postJson('/api/v1/student/subscriptions', $payload)->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Subscription::count());
    }

    public function test_a_payment_reference_cannot_be_reused_by_another_student(): void
    {
        Subscription::factory()->create(['payment_reference' => 'pay_taken']);

        $this->postJson('/api/v1/student/subscriptions', [
            'plan' => 'monthly',
            'course_ids' => [Course::factory()->create()->id],
            'payment_reference' => 'pay_taken',
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_reference');
    }

    public function test_a_student_cannot_hold_two_subscriptions_in_service(): void
    {
        Subscription::factory()->for($this->student, 'student')->create();

        $this->postJson('/api/v1/student/subscriptions', [
            'plan' => 'annual',
            'course_ids' => [Course::factory()->create()->id],
            'payment_reference' => 'pay_second',
        ])->assertUnprocessable()->assertJsonValidationErrors('plan');
    }

    public function test_subscribing_validates_plan_and_courses(): void
    {
        $this->postJson('/api/v1/student/subscriptions', ['plan' => 'weekly', 'course_ids' => [999_999]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['plan', 'course_ids.0', 'payment_reference']);
    }

    public function test_students_see_only_their_own_subscriptions(): void
    {
        $mine = Subscription::factory()->for($this->student, 'student')->create();
        $theirs = Subscription::factory()->create();

        $this->getJson('/api/v1/student/subscriptions')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->getJson("/api/v1/student/subscriptions/{$mine->id}")->assertOk();
        $this->getJson("/api/v1/student/subscriptions/{$theirs->id}")->assertForbidden();
    }

    public function test_courses_can_be_added_and_removed_but_never_the_last_one(): void
    {
        $subscription = Subscription::factory()->for($this->student, 'student')->create();
        [$first, $second] = Course::factory()->count(2)->create();
        $subscription->courses()->attach($first);

        $this->postJson("/api/v1/student/subscriptions/{$subscription->id}/courses", ['course_id' => $second->id])
            ->assertOk()
            ->assertJsonCount(2, 'data.courses');
        $this->deleteJson("/api/v1/student/subscriptions/{$subscription->id}/courses/{$first->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.courses');
        $this->deleteJson("/api/v1/student/subscriptions/{$subscription->id}/courses/{$second->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('course');
    }

    public function test_a_student_cannot_change_someone_elses_courses(): void
    {
        $theirs = Subscription::factory()->create();

        $this->postJson("/api/v1/student/subscriptions/{$theirs->id}/courses", ['course_id' => Course::factory()->create()->id])
            ->assertForbidden();
    }

    public function test_a_mid_term_refund_returns_the_unserved_days_and_is_safe_to_repeat(): void
    {
        $subscription = Subscription::factory()
            ->for($this->student, 'student')
            ->plan(SubscriptionPlan::Quarterly, CarbonImmutable::today()->subDays(10), 79_900)
            ->create();
        $expectedRefund = 79_900 - $subscription->earnedThroughMinor(CarbonImmutable::yesterday());

        $this->postJson("/api/v1/student/subscriptions/{$subscription->id}/refund")
            ->assertOk()
            ->assertJsonPath('data.status', 'refunded')
            ->assertJsonPath('data.refund_amount_minor', $expectedRefund)
            ->assertJsonPath('data.service_ends_on', CarbonImmutable::yesterday()->toDateString());

        $this->postJson("/api/v1/student/subscriptions/{$subscription->id}/refund")
            ->assertOk()
            ->assertJsonPath('data.refund_amount_minor', $expectedRefund);
    }

    public function test_a_student_cannot_refund_someone_elses_subscription(): void
    {
        $theirs = Subscription::factory()->create();

        $this->postJson("/api/v1/student/subscriptions/{$theirs->id}/refund")->assertForbidden();
        $this->assertNull($theirs->fresh()->refunded_on);
    }
}
