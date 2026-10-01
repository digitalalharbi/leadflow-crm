<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomFieldValueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->field?->key,
            'label' => $this->field?->label,
            'type' => $this->field?->type,
            'value' => $this->value,
        ];
    }
}
