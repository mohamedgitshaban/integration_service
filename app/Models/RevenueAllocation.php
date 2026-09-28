<?php

namespace App\Models;

use App\Enums\AllocationKind;
use Database\Factories\RevenueAllocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'subscription_id', 'kind', 'period_start', 'period_end',
    'gross_minor', 'platform_minor', 'instructor_pool_minor',
])]
class RevenueAllocation extends Model
{
    /** @use HasFactory<RevenueAllocationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'kind' => AllocationKind::class,
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'gross_minor' => 'integer',
            'platform_minor' => 'integer',
            'instructor_pool_minor' => 'integer',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
