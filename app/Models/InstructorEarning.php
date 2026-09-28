<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstructorEarning extends Model
{
    protected $fillable = [
        'instructor_id',
        'subscription_id',
        'gross_amount',
        'platform_fee',
        'net_amount',
        'status',
    ];

    protected $casts = [
        'gross_amount' => 'decimal:2',
        'platform_fee' => 'decimal:2',
        'net_amount' => 'decimal:2',
    ];
    
    public function instructor()
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }
}
