<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\SubscriptionResource;
use App\Models\Course;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The courses a student takes under a subscription. They decide how future
 * days' revenue is split between instructors.
 */
class SubscriptionCourseController extends Controller
{
    public function store(Request $request, Subscription $subscription, SubscriptionService $subscriptions): SubscriptionResource
    {
        Gate::authorize('update', $subscription);
        $validated = $request->validate(['course_id' => ['required', 'integer', Rule::exists('courses', 'id')]]);

        $subscriptions->addCourse($subscription, Course::findOrFail($validated['course_id']));

        return new SubscriptionResource($subscription->load('courses.instructor'));
    }

    public function destroy(Subscription $subscription, Course $course, SubscriptionService $subscriptions): SubscriptionResource
    {
        Gate::authorize('update', $subscription);

        $subscriptions->removeCourse($subscription, $course);

        return new SubscriptionResource($subscription->load('courses.instructor'));
    }
}
