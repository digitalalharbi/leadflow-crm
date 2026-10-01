<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeadMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'direction' => $this->direction?->value,
            'channel' => $this->channel?->value,
            'message_type' => $this->message_type,
            'template_name' => $this->template_name,
            'body' => $this->body,
            'status' => $this->status?->value,

            'sent_at' => $this->sent_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),

            // The code is for support; the reason is what a rep can act on.
            'failure_code' => $this->failure_code,
            'failure_reason' => $this->failure_reason,

            'sent_by' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->uuid,
                'name' => $this->user->name,
            ] : null),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
