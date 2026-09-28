<?php

namespace App\Http\Resources\V1;

use App\Models\InstructorBalance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InstructorBalance
 */
class BalanceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $minimumMinor = (int) config('ledger.minimum_payout_minor');
        $hasPayoutDetails = filled($request->user()?->bank_account_number);

        return [
            'currency' => config('ledger.currency'),
            'earned_minor' => $this->earned_minor,
            'in_flight_minor' => $this->reserved_minor,
            'paid_minor' => $this->paid_minor,
            'outstanding_minor' => $this->outstandingMinor(),
            'minimum_withdrawal_minor' => $minimumMinor,
            'has_payout_details' => $hasPayoutDetails,
            'can_withdraw' => $hasPayoutDetails && $this->outstandingMinor() >= $minimumMinor,
        ];
    }
}
