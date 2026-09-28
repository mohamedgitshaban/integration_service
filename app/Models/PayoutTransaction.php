<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayoutTransaction extends Model
{
    protected $fillable = [
        'payout_batch_id',
        'instructor_id',
        'instructor_earning_id',
        'amount',
        'currency',
        'status',
        'provider_reference',
        'idempotency_key',
        'error_message',
    ];

    public function payoutBatch()
    {
        return $this->belongsTo(PayoutBatch::class, 'payout_batch_id');
    }

    public function instructor()
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function instructorEarning()
    {
        return $this->belongsTo(InstructorEarning::class, 'instructor_earning_id');
    }
}
