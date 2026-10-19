<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'service' => $this->service,
            'language' => $this->language,
            'developer_name' => $this->developer_name,
            'location' => $this->location,
            'starting_price' => $this->starting_price !== null ? (float) $this->starting_price : null,
            'status' => $this->status,
            'notes' => $this->notes,
            'company' => $this->whenLoaded('company', fn () => $this->company ? [
                'id' => $this->company->uuid,
                'name' => $this->company->name,
                'color' => $this->company->color,
            ] : null),
            'leads_count' => $this->when(isset($this->leads_count), fn () => (int) $this->leads_count),
            /*
             * Who works this project. Empty means anybody, which is what every
             * workspace starts as — the routing only engages once somebody says
             * a project has a team.
             */
            'team' => $this->whenLoaded('team', fn () => $this->team->map(fn ($member) => [
                'id' => $member->uuid,
                'name' => $member->name,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
