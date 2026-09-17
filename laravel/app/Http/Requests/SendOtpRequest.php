<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone'  => ['required', 'string', 'min:8', 'max:20'],
            'locale' => ['sometimes', 'string', 'max:10'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required' => 'Nomor telepon wajib diisi.',
        ];
    }
}
