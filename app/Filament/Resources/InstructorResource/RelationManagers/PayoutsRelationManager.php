<?php

namespace App\Filament\Resources\InstructorResource\RelationManagers;

use App\Filament\Resources\PayoutResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

/**
 * The instructor's payouts, with the same columns as the Payouts screen.
 */
class PayoutsRelationManager extends RelationManager
{
    protected static string $relationship = 'payouts';

    protected static ?string $title = 'Payout history';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return PayoutResource::table($table);
    }
}
