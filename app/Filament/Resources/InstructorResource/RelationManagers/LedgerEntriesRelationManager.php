<?php

namespace App\Filament\Resources\InstructorResource\RelationManagers;

use App\Enums\LedgerEntryType;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Every signed money movement behind the balance: earnings, refund
 * clawbacks, payout reservations and payout reversals.
 */
class LedgerEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'ledgerEntries';

    protected static ?string $title = 'Ledger';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_on')->date()->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (LedgerEntryType $state): string => str($state->value)->replace('_', ' ')->title())
                    ->color(fn (LedgerEntryType $state): string => match ($state) {
                        LedgerEntryType::Earning => 'success',
                        LedgerEntryType::Clawback => 'danger',
                        LedgerEntryType::Payout => 'info',
                        LedgerEntryType::PayoutReversal => 'warning',
                    }),
                TextColumn::make('amount_minor')
                    ->label('Amount')
                    ->money('EGP', divideBy: 100)
                    ->alignEnd()
                    ->color(fn (int $state): string => $state < 0 ? 'danger' : 'success'),
                TextColumn::make('allocation.subscription_id')->label('Subscription')->placeholder('-'),
                TextColumn::make('payout_id')->label('Payout')->placeholder('-'),
                TextColumn::make('idempotency_key')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('type')->options(collect(LedgerEntryType::cases())->mapWithKeys(
                    fn (LedgerEntryType $type) => [$type->value => str($type->value)->replace('_', ' ')->title()->toString()]
                )),
            ]);
    }
}
