<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->uuid,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'duration_minutes' => $this->duration_minutes,
            'status' => $this->status,
            'location' => $this->location,
            'notes' => $this->notes,
            'outcome_note' => $this->outcome_note,
            // Computed here so the UI never has to compare clocks itself, and
            // never disagrees with the server about what "past" means.
            'is_past' => $this->isPast(),
            'lead' => $this->whenLoaded('lead', fn () => [
                'id' => $this->lead->uuid,
                'full_name' => $this->lead->full_name,
                'phone' => $this->lead->phone,
                'status' => $this->lead->status?->value,
            ]),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? [
                'id' => $this->assignee->uuid,
                'name' => $this->assignee->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
