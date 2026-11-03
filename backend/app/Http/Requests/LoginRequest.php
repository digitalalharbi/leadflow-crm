<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'tenant' => ['nullable', 'string', 'max:64'],
            'code' => ['nullable', 'string', 'max:32'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }
}
