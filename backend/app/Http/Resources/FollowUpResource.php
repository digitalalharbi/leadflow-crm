<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FollowUpResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'due_at' => $this->due_at?->toIso8601String(),
            'note' => $this->note,
            'status' => $this->status,
            'outcome' => $this->outcome,
            'outcome_note' => $this->outcome_note,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'is_overdue' => $this->status === 'pending' && $this->due_at?->isPast(),
            'lead' => $this->whenLoaded('lead', fn () => $this->lead ? [
                'id' => $this->lead->uuid,
                'full_name' => $this->lead->full_name,
                'phone' => $this->lead->phone,
                'whatsapp' => $this->lead->phone_normalized ? ltrim($this->lead->phone_normalized, '+') : null,
                'status' => $this->lead->status?->value,
                'temperature' => $this->lead->temperature?->value,
            ] : null),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? [
                'id' => $this->assignee->uuid,
                'name' => $this->assignee->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
