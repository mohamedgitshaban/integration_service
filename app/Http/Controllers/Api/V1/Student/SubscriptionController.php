<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Enums\SubscriptionPlan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSubscriptionRequest;
use App\Http\Resources\V1\SubscriptionResource;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class SubscriptionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return SubscriptionResource::collection(
            $request->user()->subscriptions()->with('courses.instructor')->latest('id')->paginate(15)
        );
    }

    /**
     * 201 when the subscription is created; 200 with the same subscription
     * when the payment reference was already used by this student (replay).
     */
    public function store(StoreSubscriptionRequest $request, SubscriptionService $subscriptions): JsonResponse
    {
        [$subscription, $created] = $subscriptions->purchase(
            $request->user(),
            SubscriptionPlan::from($request->validated('plan')),
            array_map('intval', $request->validated('course_ids')),
            $request->validated('payment_reference'),
        );

        return (new SubscriptionResource($subscription->load('courses.instructor')))
            ->response()
            ->setStatusCode($created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function show(Subscription $subscription): SubscriptionResource
    {
        Gate::authorize('view', $subscription);

        return new SubscriptionResource($subscription->load('courses.instructor'));
    }
}
