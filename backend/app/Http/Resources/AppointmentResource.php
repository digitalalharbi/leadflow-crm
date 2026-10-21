<?php

namespace App\Http\Resources;

use App\Domain\Visits\VisitWorkflow;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->uuid,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'duration_minutes' => $this->duration_minutes,
            'status' => $this->status === 'scheduled' ? 'confirmed' : $this->status,
            'source' => $this->source,
            'requested_at' => $this->requested_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'rescheduled_count' => (int) $this->rescheduled_count,
            'outcome' => $this->outcome,
            // What a screen may offer next, from the one place that knows.
            'moves' => VisitWorkflow::movesFrom($this->status),
            'project' => $this->whenLoaded('project', fn () => $this->project ? ['id' => $this->project->uuid, 'name' => $this->project->name] : null),
            'property' => $this->whenLoaded('property', fn () => $this->property ? ['id' => $this->property->uuid, 'title' => $this->property->title] : null),
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
