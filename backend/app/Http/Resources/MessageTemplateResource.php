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
            'subject' => $this->subject,
            'body' => $this->body,
            'channel' => $this->channel?->value,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'meta_template_name' => $this->meta_template_name,
            'meta_language' => $this->meta_language,
            'meta_parameters' => $this->meta_parameters ?? [],
            'purpose' => $this->purpose,
            'project_id' => $this->project?->uuid,
            'project_name' => $this->project?->name,
            'language' => $this->language,
            'stage' => $this->stage,
            'audience' => $this->audience,
            // Whether this message can start a conversation, which is the one
            // thing the screen needs to know about it.
            'can_start_conversation' => filled($this->meta_template_name),
        ];
    }
}
