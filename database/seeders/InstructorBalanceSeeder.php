<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds every instructor's cached balance from the ledger and payouts,
 * and fails loudly if the two disagree. Run last, it doubles as an
 * integrity check on everything seeded before it; it is also the way to
 * repair balances after using the display-only money factories.
 */
class InstructorBalanceSeeder extends Seeder
{
    public function run(LedgerService $ledger): void
    {
        User::query()->where('role', 'instructor')->orderBy('id')->pluck('id')->each(
            fn (int $instructorId) => DB::transaction(fn () => $ledger->rebuildBalance($instructorId))
        );
    }
}
