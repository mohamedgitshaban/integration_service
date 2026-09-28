<?php

namespace App\Filament\Resources;

use App\Enums\PayoutStatus;
use App\Filament\Resources\InstructorResource\RelationManagers\PayoutsRelationManager;
use App\Filament\Resources\PayoutResource\Pages;
use App\Models\Payout;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

/**
 * Read-only payout history across all instructors. "Unknown" payouts are
 * waiting for a provider status check; they are never re-sent.
 */
class PayoutResource extends Resource
{
    protected static ?string $model = Payout::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('instructor.name')
                    ->searchable()
                    ->hidden(fn (Component $livewire): bool => $livewire instanceof PayoutsRelationManager),
                TextColumn::make('amount_minor')
                    ->label('Amount')
                    ->money('EGP', divideBy: 100)
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PayoutStatus $state): string => ucfirst($state->value))
                    ->color(fn (PayoutStatus $state): string => match ($state) {
                        PayoutStatus::Succeeded => 'success',
                        PayoutStatus::Failed => 'danger',
                        PayoutStatus::Unknown => 'warning',
                        PayoutStatus::Pending, PayoutStatus::Processing => 'gray',
                    }),
                TextColumn::make('attempts')->alignCenter(),
                TextColumn::make('provider_reference')->placeholder('-')->copyable(),
                TextColumn::make('last_error')->placeholder('-')->limit(40)->tooltip(fn (Payout $record): ?string => $record->last_error)->wrap(),
                TextColumn::make('created_at')->label('Reserved at')->dateTime()->sortable(),
                TextColumn::make('settled_at')->dateTime()->placeholder('Not settled')->sortable(),
                TextColumn::make('payout_run_id')->label('Run')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('idempotency_key')->copyable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')->options(collect(PayoutStatus::cases())->mapWithKeys(
                    fn (PayoutStatus $status) => [$status->value => ucfirst($status->value)]
                )),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayouts::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
