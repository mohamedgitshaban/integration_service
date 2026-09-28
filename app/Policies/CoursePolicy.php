<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\User;

class CoursePolicy
{
    /**
     * Instructors may edit only the courses they teach.
     */
    public function update(User $user, Course $course): bool
    {
        return $course->instructor_id === $user->id;
    }
}
