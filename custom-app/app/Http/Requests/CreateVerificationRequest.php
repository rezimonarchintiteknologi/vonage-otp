<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:24'],
            'purpose' => ['sometimes', 'string', 'max:32', Rule::in(['login', 'signup', 'transaction', 'reset_password', 'other'])],
            'channels' => ['sometimes', 'array', 'max:3'],
            'channels.*' => ['string', Rule::in(['whatsapp', 'sms'])],
            'metadata' => ['sometimes', 'array'],
        ];
    }
}
