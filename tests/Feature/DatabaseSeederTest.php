<?php

namespace Tests\Feature;

use App\Enums\LedgerEntryType;
use App\Enums\PayoutStatus;
use App\Enums\SubscriptionStatus;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\PayoutRun;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(UserSeeder::class, new UserSeeder(instructorCount: 6, studentCount: 40));
        $this->seed();
    }

    public function test_it_creates_the_fixed_login_accounts(): void
    {
        foreach (['admin@example.com' => 'admin', 'instructor@example.com' => 'instructor', 'student@example.com' => 'student'] as $email => $role) {
            $user = User::where('email', $email)->sole();
            $this->assertSame($role, $user->role);
            $this->assertTrue(Hash::check('password', $user->password));
        }

        $this->assertNull(User::where('email', 'no-bank@example.com')->sole()->bank_account_number);
    }

    public function test_it_builds_every_part_of_the_money_flow(): void
    {
        $this->assertSame(40, Subscription::count());
        $this->assertSame(2, Subscription::where('status', SubscriptionStatus::Refunded)->count());
        $this->assertSame(1, PayoutRun::count());
        $this->assertGreaterThan(0, Payout::where('status', PayoutStatus::Succeeded)->count());
        $this->assertSame(0, Payout::whereIn('status', [PayoutStatus::Pending, PayoutStatus::Processing, PayoutStatus::Unknown])->count());
        $this->assertGreaterThan(0, LedgerEntry::where('type', LedgerEntryType::Earning)->count());
        $this->assertTrue(User::where('email', 'student@example.com')->sole()->subscriptions()->sole()->courses()->exists());
    }

    public function test_it_leaves_earnings_outstanding_for_a_live_payout_demo(): void
    {
        $this->assertGreaterThan(0, InstructorBalance::all()->sum(fn (InstructorBalance $balance) => max(0, $balance->outstandingMinor())));
    }

    public function test_every_balance_matches_its_ledger(): void
    {
        foreach (InstructorBalance::all() as $balance) {
            $this->assertSame(
                (int) LedgerEntry::where('instructor_id', $balance->instructor_id)->sum('amount_minor'),
                $balance->outstandingMinor(),
            );
        }
    }
}
