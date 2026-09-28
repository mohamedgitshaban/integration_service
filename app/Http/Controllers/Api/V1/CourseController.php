<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CourseResource;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The public course catalogue.
 */
class CourseController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $courses = Course::query()
            ->with('instructor')
            ->when($request->filled('search'), fn ($query) => $query->where('title', 'like', '%'.$request->string('search').'%'))
            ->orderBy('title')
            ->paginate(25)
            ->withQueryString();

        return CourseResource::collection($courses);
    }

    public function show(Course $course): CourseResource
    {
        return new CourseResource($course->load('instructor'));
    }
}
