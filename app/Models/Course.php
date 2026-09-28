<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    protected $fillable = [
        'instructor_id',
        'title',
    ];

    public function instructor()
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function subscriptions()
    {
        return $this->belongsToMany(Subscription::class, 'subscription_courses', 'course_id', 'subscription_id');
    }
}
