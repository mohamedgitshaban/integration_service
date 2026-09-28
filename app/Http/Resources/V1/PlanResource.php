<?php

namespace App\Http\Resources\V1;

use App\Enums\SubscriptionPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property SubscriptionPlan $resource
 */
class PlanResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'plan' => $this->resource->value,
            'months' => $this->resource->months(),
            'price_minor' => $this->resource->priceMinor(),
            'currency' => config('ledger.currency'),
        ];
    }
}
