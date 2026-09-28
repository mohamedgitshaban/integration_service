<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InstructorResource\Pages;
use App\Filament\Resources\InstructorResource\RelationManagers;
use App\Models\InstructorBalance;
use App\Models\User;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only view of what each instructor has earned, what is in flight,
 * what has been paid, and what is still outstanding.
 */
class InstructorResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $modelLabel = 'instructor';

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?int $navigationSort = 1;

    /**
     * Balance figures come from correlated subqueries rather than a join, so
     * the users.id route key stays unambiguous and every figure is sortable.
     */
    public static function getEloquentQuery(): Builder
    {
        $balance = fn (string $expression) => InstructorBalance::query()
            ->selectRaw($expression)
            ->whereColumn('instructor_balances.instructor_id', 'users.id')
            ->limit(1);

        return parent::getEloquentQuery()
            ->where('role', 'instructor')
            ->select('users.*')
            ->addSelect([
                'earned_minor' => $balance('earned_minor'),
                'reserved_minor' => $balance('reserved_minor'),
                'paid_minor' => $balance('paid_minor'),
                'outstanding_minor' => $balance('earned_minor - reserved_minor - paid_minor'),
            ])
            ->with('balance');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->toggleable(),
                IconColumn::make('has_payout_details')
                    ->label('Bank details')
                    ->state(fn (User $record): bool => filled($record->bank_account_number))
                    ->boolean(),
                self::moneyColumn('earned_minor', 'Earned'),
                self::moneyColumn('reserved_minor', 'In flight'),
                self::moneyColumn('paid_minor', 'Paid'),
                self::moneyColumn('outstanding_minor', 'Outstanding')
                    ->weight('bold')
                    ->color(fn ($state): ?string => (int) $state < 0 ? 'danger' : null),
            ])
            ->defaultSort('outstanding_minor', 'desc')
            ->filters([
                Filter::make('owed')
                    ->label('Owed money')
                    ->query(fn (Builder $query) => $query->whereHas('balance', fn (Builder $balance) => $balance->whereRaw('earned_minor - reserved_minor - paid_minor > 0'))),
                Filter::make('in_debt')
                    ->label('In debt (clawed back after payout)')
                    ->query(fn (Builder $query) => $query->whereHas('balance', fn (Builder $balance) => $balance->whereRaw('earned_minor - reserved_minor - paid_minor < 0'))),
                Filter::make('in_flight')
                    ->label('Payout in flight')
                    ->query(fn (Builder $query) => $query->whereHas('balance', fn (Builder $balance) => $balance->where('reserved_minor', '>', 0))),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $money = fn (string $name, string $label, callable $state) => TextEntry::make($name)
            ->label($label)
            ->state(fn (User $record): int => $state($record->balance ?? new InstructorBalance))
            ->money('EGP', divideBy: 100);

        return $infolist->schema([
            Section::make('Instructor')
                ->columns(3)
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('email'),
                    TextEntry::make('bank_account_number')->label('Bank account')->placeholder('Not on file: payouts are skipped'),
                ]),
            Section::make('Balance')
                ->description('Outstanding = earned - in flight - paid. Earned is net of refund clawbacks; in flight is reserved for payouts whose outcome is not yet final.')
                ->columns(4)
                ->schema([
                    $money('earned', 'Earned', fn (InstructorBalance $balance) => $balance->earned_minor),
                    $money('in_flight', 'In flight', fn (InstructorBalance $balance) => $balance->reserved_minor),
                    $money('paid', 'Paid', fn (InstructorBalance $balance) => $balance->paid_minor),
                    $money('outstanding', 'Outstanding', fn (InstructorBalance $balance) => $balance->outstandingMinor())
                        ->weight('bold')
                        ->color(fn (User $record): ?string => ($record->balance?->outstandingMinor() ?? 0) < 0 ? 'danger' : 'success'),
                ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PayoutsRelationManager::class,
            RelationManagers\LedgerEntriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInstructors::route('/'),
            'view' => Pages\ViewInstructor::route('/{record}'),
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

    private static function moneyColumn(string $name, string $label): TextColumn
    {
        return TextColumn::make($name)
            ->label($label)
            ->money('EGP', divideBy: 100)
            ->default(0)
            ->alignEnd()
            ->sortable();
    }
}
