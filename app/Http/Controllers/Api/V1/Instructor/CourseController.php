<?php

namespace App\Http\Controllers\Api\V1\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreCourseRequest;
use App\Http\Resources\V1\CourseResource;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The courses an instructor teaches.
 */
class CourseController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return CourseResource::collection(
            $request->user()->courses()->withCount('subscriptions')->orderBy('title')->paginate(25)
        );
    }

    public function store(StoreCourseRequest $request): JsonResponse
    {
        $course = $request->user()->courses()->create($request->safe()->only(['title']));

        return (new CourseResource($course))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(StoreCourseRequest $request, Course $course): CourseResource
    {
        Gate::authorize('update', $course);

        $course->update($request->safe()->only(['title']));

        return new CourseResource($course);
    }
}
