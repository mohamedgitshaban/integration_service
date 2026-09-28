<?php

namespace App\Http\Resources\V1;

use App\Enums\PayoutStatus;
use App\Models\Payout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * @mixin Payout
 */
class PayoutResource extends JsonResource
{
    /**
     * Transform the resource into an array. The destination is masked; the
     * internal provider idempotency key is never exposed.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->payout_run_id === null ? 'withdrawal' : 'scheduled',
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'destination_account' => Str::mask($this->destination_account, '*', 0, -4),
            'provider_reference' => $this->provider_reference,
            'failure_reason' => $this->when($this->status === PayoutStatus::Failed, $this->last_error),
            'requested_at' => $this->created_at?->toIso8601String(),
            'settled_at' => $this->settled_at?->toIso8601String(),
        ];
    }
}
