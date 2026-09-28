<?php

namespace App\Models;

use App\Enums\PayoutStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property PayoutStatus $status
 * @property int $amount_minor
 * @property string $idempotency_key
 */
#[Fillable([
    'payout_run_id', 'instructor_id', 'amount_minor', 'currency', 'status', 'idempotency_key',
    'provider_reference', 'attempts', 'last_error', 'sent_at', 'settled_at',
])]
class Payout extends Model
{
    protected function casts(): array
    {
        return [
            'status' => PayoutStatus::class,
            'amount_minor' => 'integer',
            'attempts' => 'integer',
            'sent_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayoutRun::class, 'payout_run_id');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
