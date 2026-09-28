<?php

namespace Tests\Feature;

use App\Filament\Resources\InstructorResource;
use App\Filament\Resources\InstructorResource\Pages\ListInstructors;
use App\Filament\Resources\InstructorResource\Pages\ViewInstructor;
use App\Filament\Resources\InstructorResource\RelationManagers\PayoutsRelationManager;
use App\Filament\Resources\PayoutResource;
use App\Filament\Widgets\LedgerOverview;
use App\Models\InstructorBalance;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Number;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admins_can_open_the_panel(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->student()->create())->get('/admin')->assertForbidden();
        $this->actingAs(User::factory()->instructor()->create())->get('/admin')->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get('/admin')->assertOk();
    }

    public function test_admin_can_open_every_screen(): void
    {
        $instructor = User::factory()->instructor()->create();
        InstructorBalance::factory()->for($instructor, 'instructor')->create();
        $this->actingAs(User::factory()->admin()->create());

        $this->get(InstructorResource::getUrl('index'))->assertOk();
        $this->get(InstructorResource::getUrl('view', ['record' => $instructor]))->assertOk();
        $this->get(PayoutResource::getUrl('index'))->assertOk();
    }

    public function test_instructor_list_shows_each_balance_and_only_instructors(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $owed = User::factory()->instructor()->create();
        InstructorBalance::factory()->for($owed, 'instructor')->create(['earned_minor' => 500_000, 'reserved_minor' => 100_000, 'paid_minor' => 150_050]);
        $student = User::factory()->student()->create();

        Livewire::test(ListInstructors::class)
            ->assertCanSeeTableRecords([$owed])
            ->assertCanNotSeeTableRecords([$student])
            ->assertSee($this->egp(5000.00))
            ->assertSee($this->egp(1000.00))
            ->assertSee($this->egp(1500.50))
            ->assertSee($this->egp(2499.50));
    }

    public function test_the_in_debt_filter_finds_instructors_clawed_back_after_payout(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $inDebt = User::factory()->instructor()->create();
        InstructorBalance::factory()->for($inDebt, 'instructor')->create(['earned_minor' => 0, 'reserved_minor' => 0, 'paid_minor' => 21_000]);
        $owed = User::factory()->instructor()->create();
        InstructorBalance::factory()->for($owed, 'instructor')->create(['earned_minor' => 50_000, 'reserved_minor' => 0, 'paid_minor' => 0]);

        Livewire::test(ListInstructors::class)
            ->filterTable('in_debt')
            ->assertCanSeeTableRecords([$inDebt])
            ->assertCanNotSeeTableRecords([$owed]);
    }

    public function test_payout_history_shows_only_that_instructors_payouts(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $instructor = User::factory()->instructor()->create();
        $theirs = collect([
            Payout::factory()->for($instructor, 'instructor')->succeeded()->create(),
            Payout::factory()->for($instructor, 'instructor')->failed()->create(),
            Payout::factory()->for($instructor, 'instructor')->unknown()->create(),
        ]);
        $someoneElses = Payout::factory()->succeeded()->create();

        Livewire::test(PayoutsRelationManager::class, ['ownerRecord' => $instructor, 'pageClass' => ViewInstructor::class])
            ->assertCanSeeTableRecords($theirs)
            ->assertCanNotSeeTableRecords([$someoneElses])
            ->assertSee(['Succeeded', 'Failed', 'Unknown']);
    }

    public function test_dashboard_totals_add_up_the_cached_balances(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        InstructorBalance::factory()->create(['earned_minor' => 100_000, 'reserved_minor' => 20_000, 'paid_minor' => 50_000]);
        InstructorBalance::factory()->create(['earned_minor' => 0, 'reserved_minor' => 0, 'paid_minor' => 10_000]);
        Payout::factory()->unknown()->create();

        Livewire::test(LedgerOverview::class)
            ->assertSee($this->egp(1000.00))
            ->assertSee($this->egp(600.00))
            ->assertSee($this->egp(300.00))
            ->assertSee('Recovering from clawbacks: '.$this->egp(100.00))
            ->assertSee('1 payout(s) awaiting provider status check');
    }

    /**
     * Format as the panel does (intl puts a non-breaking space after the code).
     */
    private function egp(float $amount): string
    {
        return Number::currency($amount, 'EGP');
    }
}
