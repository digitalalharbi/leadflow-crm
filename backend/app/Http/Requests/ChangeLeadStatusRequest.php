<?php

namespace App\Http\Requests;

use App\Enums\LeadStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeLeadStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(LeadStatus::values())],
            // A lost lead must say why — this is what makes the Lost Reasons report useful.
            'lost_reason_id' => [
                Rule::requiredIf(fn () => $this->input('status') === LeadStatus::Lost->value),
                'nullable',
                'uuid',
            ],
            'lost_note' => ['nullable', 'string', 'max:1000'],
            /*
             * Optional, even on a win. A sale recorded without its value is
             * still a sale, and refusing to close one until somebody knows the
             * final figure is how a CRM ends up with a stage people route
             * around. In major units — what a person types.
             */
            'deal_value' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ];
    }

    public function messages(): array
    {
        return [
            'lost_reason_id.required' => 'A lost reason is required when marking a lead as lost.',
        ];
    }
}
