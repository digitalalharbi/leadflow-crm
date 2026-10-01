<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'full_name' => $this->full_name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'phone' => $this->phone,
            'phone_normalized' => $this->phone_normalized,
            'whatsapp' => $this->phone_normalized ? ltrim($this->phone_normalized, '+') : null,
            'email' => $this->email,
            'city' => $this->city,

            'status' => $this->status?->value,
            'temperature' => $this->temperature?->value,
            'score' => $this->score,

            'platform' => $this->platform,
            'source' => $this->source,
            'campaign_name' => $this->campaign_name,
            'campaign_id' => $this->campaign_id,
            'adset_name' => $this->adset_name,
            'ad_name' => $this->ad_name,
            'form_name' => $this->form_name,
            'provider' => $this->provider,

            'budget_min' => $this->budget_min !== null ? (float) $this->budget_min : null,
            'budget_max' => $this->budget_max !== null ? (float) $this->budget_max : null,
            'purchase_purpose' => $this->purchase_purpose,
            'payment_method' => $this->payment_method,
            'financing_status' => $this->financing_status,
            'preferred_property_type' => $this->preferred_property_type,
            'preferred_location' => $this->preferred_location,
            'purchase_timeline' => $this->purchase_timeline,

            'next_follow_up_at' => $this->next_follow_up_at?->toIso8601String(),
            'last_contact_at' => $this->last_contact_at?->toIso8601String(),
            'won_at' => $this->won_at?->toIso8601String(),
            'lost_at' => $this->lost_at?->toIso8601String(),
            'lost_note' => $this->lost_note,
            're_engaged_count' => $this->re_engaged_count,
            'is_overdue' => $this->next_follow_up_at !== null
                && $this->next_follow_up_at->isPast()
                && ! $this->status?->isClosed(),

            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? [
                'id' => $this->assignee->uuid,
                'name' => $this->assignee->name,
            ] : null),
            'project' => $this->whenLoaded('project', fn () => $this->project ? [
                'id' => $this->project->uuid,
                'name' => $this->project->name,
            ] : null),
            'lost_reason' => $this->whenLoaded('lostReason', fn () => $this->lostReason ? [
                'id' => $this->lostReason->uuid,
                'label' => $this->lostReason->label,
            ] : null),
            'custom_fields' => CustomFieldValueResource::collection($this->whenLoaded('customFieldValues')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
