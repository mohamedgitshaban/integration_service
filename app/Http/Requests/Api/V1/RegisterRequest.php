<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    /**
     * Registration is public.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Admin accounts cannot be self-registered.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => ['required', Rule::in(['student', 'instructor'])],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
