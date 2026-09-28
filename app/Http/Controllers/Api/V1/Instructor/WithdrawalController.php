<?php

namespace App\Http\Controllers\Api\V1\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PayoutResource;
use App\Services\PayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Withdraw the whole outstanding balance now instead of waiting for the
 * scheduled run.
 *
 * The transfer happens asynchronously, so a new withdrawal answers 202 with
 * a pending payout; poll GET /instructor/payouts/{id} for the outcome.
 * Send an Idempotency-Key header to make retries safe: the same key returns
 * the original payout (200) instead of an error or a second payout.
 */
class WithdrawalController extends Controller
{
    public function store(Request $request, PayoutService $payouts): JsonResponse
    {
        $requestKey = $request->header('Idempotency-Key') ?: null;

        if ($requestKey !== null && strlen($requestKey) > 100) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'The Idempotency-Key header may not be longer than 100 characters.']);
        }

        [$payout, $created] = $payouts->requestWithdrawal($request->user(), $requestKey);

        return (new PayoutResource($payout->refresh()))
            ->response()
            ->setStatusCode($created ? Response::HTTP_ACCEPTED : Response::HTTP_OK);
    }
}
