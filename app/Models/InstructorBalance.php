<?php

namespace App\Models;

use Database\Factories\InstructorBalanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cached projection of the ledger. Written only inside the same transaction
 * as the ledger entry that changes it.
 *
 * @property int $earned_minor Earnings net of clawbacks.
 * @property int $reserved_minor Committed to payouts whose outcome is not final.
 * @property int $paid_minor Confirmed by the provider.
 */
#[Fillable(['instructor_id', 'earned_minor', 'reserved_minor', 'paid_minor'])]
class InstructorBalance extends Model
{
    /** @use HasFactory<InstructorBalanceFactory> */
    use HasFactory;

    protected $attributes = [
        'earned_minor' => 0,
        'reserved_minor' => 0,
        'paid_minor' => 0,
    ];

    protected function casts(): array
    {
        return [
            'earned_minor' => 'integer',
            'reserved_minor' => 'integer',
            'paid_minor' => 'integer',
        ];
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    /**
     * Owed and not yet committed to any payout. Negative after a clawback
     * that exceeds unpaid earnings; recovered from future earnings.
     */
    public function outstandingMinor(): int
    {
        return $this->earned_minor - $this->reserved_minor - $this->paid_minor;
    }
}
