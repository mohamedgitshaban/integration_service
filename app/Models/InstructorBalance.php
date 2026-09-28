<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstructorBalance extends Model
{
    protected $fillable = [
        'instructor_id',
        'pending_balance',
        'available_balance',
        'paid_out_balance',
    ];

    protected $casts = [
        'pending_balance' => 'decimal:2',
        'available_balance' => 'decimal:2',
        'paid_out_balance' => 'decimal:2',
    ];
    
    public function instructor()
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }
}
