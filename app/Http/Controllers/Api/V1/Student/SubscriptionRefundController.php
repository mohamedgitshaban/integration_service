<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Enums\RefundPolicy;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\SubscriptionResource;
use App\Models\Subscription;
use App\Services\RefundService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A student leaves mid-term. Days already served stay earned; the unserved
 * remainder is refunded. Students can only request pro-rata refunds; full
 * refunds are an admin decision. Repeating the request is harmless.
 */
class SubscriptionRefundController extends Controller
{
    public function store(Subscription $subscription, RefundService $refunds): SubscriptionResource
    {
        Gate::authorize('refund', $subscription);

        try {
            $refunded = $refunds->refund($subscription->id, CarbonImmutable::today(), RefundPolicy::ProRata);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['subscription' => $exception->getMessage()]);
        }

        return new SubscriptionResource($refunded->load('courses.instructor'));
    }
}
