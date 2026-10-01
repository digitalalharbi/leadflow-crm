<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'body' => $this->body,
            'channel' => $this->channel?->value,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
        ];
    }
}
