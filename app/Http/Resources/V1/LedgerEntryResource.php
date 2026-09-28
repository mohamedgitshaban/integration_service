<?php

namespace App\Http\Resources\V1;

use App\Models\LedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LedgerEntry
 */
class LedgerEntryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'occurred_on' => $this->occurred_on->toDateString(),
            'payout_id' => $this->payout_id,
        ];
    }
}
