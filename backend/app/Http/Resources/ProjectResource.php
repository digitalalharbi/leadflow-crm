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
            'developer_name' => $this->developer_name,
            'location' => $this->location,
            'starting_price' => $this->starting_price !== null ? (float) $this->starting_price : null,
            'status' => $this->status,
            'notes' => $this->notes,
            'leads_count' => $this->when(isset($this->leads_count), fn () => (int) $this->leads_count),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
