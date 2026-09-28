<?php

namespace App\Http\Resources\V1;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Subscription
 */
class SubscriptionResource extends JsonResource
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
            'plan' => $this->plan->value,
            'status' => $this->status->value,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'payment_reference' => $this->payment_reference,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'service_ends_on' => $this->service_ends_on->toDateString(),
            'refunded_on' => $this->refunded_on?->toDateString(),
            'refund_amount_minor' => $this->refund_amount_minor,
            'courses' => CourseResource::collection($this->whenLoaded('courses')),
        ];
    }
}
