<?php

namespace App\Http\Controllers\Api\V1\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdatePayoutDetailsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Where the instructor is paid. Payouts already reserved keep the account
 * they were reserved with; changes apply to future payouts only.
 */
class PayoutDetailsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->details($request->user()->bank_account_number)]);
    }

    public function update(UpdatePayoutDetailsRequest $request): JsonResponse
    {
        $request->user()->update($request->safe()->only(['bank_account_number']));

        return response()->json(['data' => $this->details($request->user()->bank_account_number)]);
    }

    /**
     * @return array{bank_account_number: string|null, has_payout_details: bool}
     */
    private function details(?string $accountNumber): array
    {
        return [
            'bank_account_number' => $accountNumber ? Str::mask($accountNumber, '*', 0, -4) : null,
            'has_payout_details' => filled($accountNumber),
        ];
    }
}
