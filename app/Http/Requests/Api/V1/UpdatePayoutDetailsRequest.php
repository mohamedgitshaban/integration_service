<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePayoutDetailsRequest extends FormRequest
{
    /**
     * Role is enforced by route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * An IBAN-shaped account number: two-letter country code plus digits.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'bank_account_number' => ['required', 'string', 'regex:/^[A-Z]{2}[0-9]{10,32}$/'],
        ];
    }
}
