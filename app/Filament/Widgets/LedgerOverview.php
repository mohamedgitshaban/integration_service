<?php

namespace App\Filament\Widgets;

use App\Enums\PayoutStatus;
use App\Models\InstructorBalance;
use App\Models\Payout;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * Platform-wide totals, read from the cached balances (one row per instructor)
 * so the dashboard stays cheap however large the ledger grows.
 */
class LedgerOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $totals = InstructorBalance::query()
            ->selectRaw('COALESCE(SUM(earned_minor), 0) as earned, COALESCE(SUM(reserved_minor), 0) as reserved, COALESCE(SUM(paid_minor), 0) as paid')
            ->selectRaw('COALESCE(SUM(GREATEST(earned_minor - reserved_minor - paid_minor, 0)), 0) as owed')
            ->selectRaw('COALESCE(SUM(LEAST(earned_minor - reserved_minor - paid_minor, 0)), 0) as debt')
            ->toBase()
            ->first();

        $unknown = Payout::where('status', PayoutStatus::Unknown)->count();

        return [
            Stat::make('Earned by instructors', $this->money($totals->earned)),
            Stat::make('Paid out', $this->money($totals->paid)),
            Stat::make('Outstanding (owed)', $this->money($totals->owed))
                ->description('Recovering from clawbacks: '.$this->money(abs((int) $totals->debt))),
            Stat::make('In flight', $this->money($totals->reserved))
                ->description($unknown.' payout(s) awaiting provider status check')
                ->color($unknown > 0 ? 'warning' : 'success'),
        ];
    }

    private function money(int|string $minor): string
    {
        return Number::currency(((int) $minor) / 100, 'EGP');
    }
}
