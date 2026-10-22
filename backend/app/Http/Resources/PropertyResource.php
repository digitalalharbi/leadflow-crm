<?php

namespace App\Http\Resources;

use App\Models\Property;
use App\Support\RealEstate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Property */
class PropertyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'project_id' => $this->whenLoaded('project', fn () => $this->project?->uuid),
            'project' => $this->whenLoaded('project', fn () => $this->project ? ['id' => $this->project->uuid, 'name' => $this->project->name] : null),
            'code' => $this->code,
            'title' => $this->title,
            'property_type' => $this->property_type,
            'type_label' => RealEstate::LABELS['property_type'][$this->property_type] ?? $this->property_type,
            'transaction_type' => $this->transaction_type,
            // Major units for the screen; minor in the database.
            'price' => $this->price(),
            'city' => $this->city,
            'district' => $this->district,
            'bedrooms' => $this->bedrooms,
            'bathrooms' => $this->bathrooms,
            'area_sqm' => $this->area_sqm !== null ? (float) $this->area_sqm : null,
            'floor' => $this->floor,
            'status' => $this->status,
            'features' => $this->features ?? [],
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
