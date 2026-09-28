<?php

namespace Tests\Feature\Api;

use App\Enums\LedgerEntryType;
use App\Models\Course;
use App\Models\Payout;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLedgerScenarios;
use Tests\TestCase;

class InstructorApiTest extends TestCase
{
    use BuildsLedgerScenarios, RefreshDatabase;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ledger.minimum_payout_minor' => 10_000]);
        $this->instructor = User::factory()->instructor()->create();
        Sanctum::actingAs($this->instructor);
    }

    public function test_a_new_instructor_has_an_empty_balance_and_cannot_withdraw(): void
    {
        $this->getJson('/api/v1/instructor/balance')
            ->assertOk()
            ->assertJson(['data' => [
                'currency' => 'EGP',
                'earned_minor' => 0,
                'in_flight_minor' => 0,
                'paid_minor' => 0,
                'outstanding_minor' => 0,
                'minimum_withdrawal_minor' => 10_000,
                'has_payout_details' => true,
                'can_withdraw' => false,
            ]]);
    }

    public function test_balance_reflects_earnings(): void
    {
        $this->earn($this->instructor, 25_000);

        $this->getJson('/api/v1/instructor/balance')
            ->assertOk()
            ->assertJsonPath('data.earned_minor', 25_000)
            ->assertJsonPath('data.outstanding_minor', 25_000)
            ->assertJsonPath('data.can_withdraw', true);
    }

    public function test_ledger_lists_only_the_instructors_own_entries_and_filters_by_type(): void
    {
        $this->earn($this->instructor, 1_000);
        $this->earn($this->instructor, 2_000);
        $this->earn(User::factory()->instructor()->create(), 9_999);

        $this->getJson('/api/v1/instructor/ledger')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/instructor/ledger?type=earning')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.type', LedgerEntryType::Earning->value);
        $this->getJson('/api/v1/instructor/ledger?type=clawback')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/instructor/ledger?type=bogus')->assertUnprocessable();
    }

    public function test_payout_history_is_private_and_masks_the_destination(): void
    {
        $mine = Payout::factory()->for($this->instructor, 'instructor')->succeeded()->create(['destination_account' => 'EG001234567891234']);
        $theirs = Payout::factory()->succeeded()->create();

        $this->getJson('/api/v1/instructor/payouts')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.type', 'scheduled')
            ->assertJsonPath('data.0.destination_account', '*************1234')
            ->assertJsonMissingPath('data.0.idempotency_key');
        $this->getJson("/api/v1/instructor/payouts/{$mine->id}")->assertOk();
        $this->getJson("/api/v1/instructor/payouts/{$theirs->id}")->assertForbidden();
    }

    public function test_payout_details_are_masked_on_read_and_validated_on_update(): void
    {
        $this->putJson('/api/v1/instructor/payout-details', ['bank_account_number' => 'not an iban'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('bank_account_number');

        $this->putJson('/api/v1/instructor/payout-details', ['bank_account_number' => 'EG380019000500000000263180002'])
            ->assertOk()
            ->assertJsonPath('data.has_payout_details', true)
            ->assertJsonPath('data.bank_account_number', str_repeat('*', 25).'0002');

        $this->assertSame('EG380019000500000000263180002', $this->instructor->fresh()->bank_account_number);
    }

    public function test_instructors_manage_only_their_own_courses(): void
    {
        $created = $this->postJson('/api/v1/instructor/courses', ['title' => 'Queues in Depth'])->assertCreated();
        $courseId = $created->json('data.id');
        Subscription::factory()->count(3)->create()->each(fn (Subscription $subscription) => $subscription->courses()->attach($courseId));
        $someoneElses = Course::factory()->create();

        $this->getJson('/api/v1/instructor/courses')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.students_count', 3);
        $this->putJson("/api/v1/instructor/courses/{$courseId}", ['title' => 'Queues, Properly'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Queues, Properly');
        $this->putJson("/api/v1/instructor/courses/{$someoneElses->id}", ['title' => 'Hijacked'])->assertForbidden();
    }
}
