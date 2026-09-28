<?php

namespace App\Models;

use App\Enums\PayoutRunStatus;
use Database\Factories\PayoutRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['status', 'payouts_count', 'total_minor', 'started_at', 'finished_at'])]
class PayoutRun extends Model
{
    /** @use HasFactory<PayoutRunFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => PayoutRunStatus::class,
            'payouts_count' => 'integer',
            'total_minor' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }
}
