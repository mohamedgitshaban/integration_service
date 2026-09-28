<?php

namespace Tests\Feature;

use App\Enums\AllocationKind;
use App\Enums\LedgerEntryType;
use App\Enums\PayoutRunStatus;
use App\Enums\PayoutStatus;
use App\Models\Course;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\PayoutRun;
use App\Models\RevenueAllocation;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_factory_builds_each_account_type(): void
    {
        $this->assertSame('admin', User::factory()->admin()->create()->role);
        $this->assertSame('student', User::factory()->student()->create()->role);

        $instructor = User::factory()->instructor()->create();
        $this->assertSame('instructor', $instructor->role);
        $this->assertNotNull($instructor->bank_account_number);
    }

    public function test_catalogue_and_subscription_factories_create_valid_rows(): void
    {
        $course = Course::factory()->create();
        $subscription = Subscription::factory()->create();

        $this->assertSame('instructor', $course->instructor->role);
        $this->assertSame($subscription->ends_on->toDateString(), $subscription->service_ends_on->toDateString());
    }

    public function test_money_factories_create_valid_rows_in_each_state(): void
    {
        $this->assertSame(AllocationKind::Reversal, RevenueAllocation::factory()->reversal()->create()->kind);

        $allocation = RevenueAllocation::factory()->create();
        $this->assertSame($allocation->gross_minor, $allocation->platform_minor + $allocation->instructor_pool_minor);

        $clawback = LedgerEntry::factory()->clawback()->create();
        $this->assertSame(LedgerEntryType::Clawback, $clawback->type);
        $this->assertLessThan(0, $clawback->amount_minor);

        $this->assertGreaterThanOrEqual(0, InstructorBalance::factory()->create()->outstandingMinor());
        $this->assertSame(PayoutRunStatus::Running, PayoutRun::factory()->running()->create()->status);

        $this->assertSame(PayoutStatus::Pending, Payout::factory()->create()->status);
        $this->assertNotNull(Payout::factory()->succeeded()->create()->provider_reference);
        $this->assertSame(PayoutStatus::Failed, Payout::factory()->failed()->create()->status);
        $this->assertSame(PayoutStatus::Unknown, Payout::factory()->unknown()->create()->status);
    }
}
