<?php

namespace App\Models;

use App\Enums\LedgerEntryType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only: updates and deletes throw.
 *
 * @property LedgerEntryType $type
 * @property int $amount_minor
 */
#[Fillable([
    'instructor_id', 'type', 'amount_minor', 'currency', 'revenue_allocation_id',
    'payout_id', 'idempotency_key', 'occurred_on',
])]
class LedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Ledger entries are append-only.'));
        static::deleting(fn () => throw new LogicException('Ledger entries are append-only.'));
    }

    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'amount_minor' => 'integer',
            'occurred_on' => 'immutable_date',
        ];
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(RevenueAllocation::class, 'revenue_allocation_id');
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }
}
