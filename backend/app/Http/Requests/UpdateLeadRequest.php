<?php

namespace App\Http\Requests;

use App\Enums\Platform;
use App\Support\Qualification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller authorizes against the resolved model
    }

    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'project_id' => ['nullable', 'uuid'],
            'platform' => ['nullable', Rule::in(Platform::values())],
            'source' => ['nullable', 'string', 'max:120'],
            'budget_min' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            // `gte:budget_min` on its own rejects a maximum stated without a
            // minimum, which is how most people answer "what is your budget?".
            'budget_max' => ['nullable', 'numeric', 'min:0', 'max:999999999999',
                Rule::when(fn () => filled($this->input('budget_min')), ['gte:budget_min'])],
            'purchase_purpose' => ['nullable', 'string', 'max:60'],
            'payment_method' => ['nullable', 'string', 'max:60'],
            'financing_status' => ['nullable', 'string', 'max:60'],
            'preferred_property_type' => ['nullable', 'string', 'max:120'],
            'preferred_location' => ['nullable', 'string', 'max:255'],
            'purchase_timeline' => ['nullable', 'string', 'max:60'],
            'transaction_type' => ['nullable', Rule::in(Qualification::TRANSACTIONS)],
            'preferred_district' => ['nullable', 'string', 'max:120'],
            'bedrooms' => ['nullable', 'integer', 'min:0', 'max:50'],
            'area_sqm' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'nationality' => ['nullable', 'string', 'max:60'],
            'preferred_channel' => ['nullable', Rule::in(Qualification::CHANNELS)],
            'reservation_intent' => ['nullable', 'boolean'],
            'interested_property_id' => ['nullable', 'uuid'],
            'custom' => ['nullable', 'array'],
        ];
    }
}
