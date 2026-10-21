<?php

namespace App\Http\Resources;

use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Reservation */
class ReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'status' => $this->status,
            'amount' => $this->amount_minor !== null ? $this->amount_minor / 100 : null,
            'reserved_at' => $this->reserved_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'converted_at' => $this->converted_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
            'notes' => $this->notes,
            // What a screen may offer next.
            'moves' => match ($this->status) {
                'pending' => ['confirm', 'cancel', 'convert'],
                'confirmed' => ['cancel', 'convert'],
                default => [],
            },
            'lead' => $this->whenLoaded('lead', fn () => $this->lead ? [
                'id' => $this->lead->uuid, 'full_name' => $this->lead->full_name, 'phone' => $this->lead->phone,
            ] : null),
            'project' => $this->whenLoaded('project', fn () => $this->project ? ['id' => $this->project->uuid, 'name' => $this->project->name] : null),
            'property' => $this->whenLoaded('property', fn () => $this->property ? [
                'id' => $this->property->uuid, 'title' => $this->property->title, 'price' => $this->property->price(), 'status' => $this->property->status,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
