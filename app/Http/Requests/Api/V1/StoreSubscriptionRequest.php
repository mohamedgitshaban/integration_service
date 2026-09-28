<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\SubscriptionPlan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubscriptionRequest extends FormRequest
{
    /**
     * Role is enforced by route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * There is deliberately no amount field: the price comes from config.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'plan' => ['required', Rule::enum(SubscriptionPlan::class)],
            'course_ids' => ['required', 'array', 'min:1', 'max:20'],
            'course_ids.*' => ['integer', 'distinct', Rule::exists('courses', 'id')],
            'payment_reference' => ['required', 'string', 'max:100'],
        ];
    }
}
