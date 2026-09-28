<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayoutBatch extends Model
{
    protected $fillable = [
        'status',
        'total_amount',
        'currency',
        'payout_date',
    ];

    public function transactions()
    {
        return $this->hasMany(PayoutTransaction::class, 'payout_batch_id');
    }
}
