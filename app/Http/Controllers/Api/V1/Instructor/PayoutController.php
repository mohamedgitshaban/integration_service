<?php

namespace App\Http\Controllers\Api\V1\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PayoutResource;
use App\Models\Payout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Payout history: scheduled payouts and on-demand withdrawals.
 */
class PayoutController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return PayoutResource::collection($request->user()->payouts()->latest('id')->paginate(25));
    }

    public function show(Payout $payout): PayoutResource
    {
        Gate::authorize('view', $payout);

        return new PayoutResource($payout);
    }
}
