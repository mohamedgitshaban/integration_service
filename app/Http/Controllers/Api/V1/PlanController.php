<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SubscriptionPlan;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PlanResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PlanController extends Controller
{
    /**
     * The plans on sale and their current prices.
     */
    public function __invoke(): AnonymousResourceCollection
    {
        return PlanResource::collection(SubscriptionPlan::cases());
    }
}
