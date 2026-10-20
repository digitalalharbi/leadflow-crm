<?php

namespace App\Http\Resources;

use App\Domain\Leads\LeadClassification;
use App\Models\Property;
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
            /*
             * The language to expect on the call, inferred from the number's
             * country code. Null when the number says nothing, which the
             * interface shows as nothing rather than as a guess — see
             * PhoneLanguage.
             */
            'language' => $this->resource->language(),
            'email' => $this->email,
            'city' => $this->city,

            'status' => $this->status?->value,
            'temperature' => $this->temperature?->value,
            // Hot, and the one to call first. Not a fourth temperature.
            'is_priority' => (bool) $this->is_priority,
            // Whether the journey is still talking to them, or it is a person's
            // turn — and why.
            'automation_state' => $this->automation_state,
            'needs_human_reason' => $this->needs_human_reason,
            // One word for how seriously to take the lead, and why — see LeadClassification.
            'classification' => LeadClassification::for($this->resource),
            // The project's own questions say this is not a buyer for it.
            'wrong_fit' => (bool) $this->wrong_fit,
            'score' => $this->score,
            'qualification_status' => $this->qualification_status?->value,
            'qualified_at' => $this->qualified_at?->toIso8601String(),

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
            'appointment_interest' => $this->appointment_interest,
            // Real estate facts: what they want, where, and how they want to be reached.
            'transaction_type' => $this->transaction_type,
            'preferred_district' => $this->preferred_district,
            'bedrooms' => $this->bedrooms,
            'area_sqm' => $this->area_sqm !== null ? (float) $this->area_sqm : null,
            'nationality' => $this->nationality,
            'preferred_channel' => $this->preferred_channel,
            'reservation_intent' => (bool) $this->reservation_intent,
            'interested_property_id' => $this->interested_property_id ? Property::withoutGlobalScopes()->whereKey($this->interested_property_id)->value('uuid') : null,

            'next_follow_up_at' => $this->next_follow_up_at?->toIso8601String(),
            'last_contact_at' => $this->last_contact_at?->toIso8601String(),
            'first_contact_at' => $this->first_contact_at?->toIso8601String(),
            'first_response_at' => $this->first_response_at?->toIso8601String(),
            'whatsapp_opt_out_at' => $this->whatsapp_opt_out_at?->toIso8601String(),
            'email_opt_out_at' => $this->email_opt_out_at?->toIso8601String(),
            'email_bounced_at' => $this->email_bounced_at?->toIso8601String(),
            'won_at' => $this->won_at?->toIso8601String(),
            // Major units for the screen; minor in the database. Null means
            // nobody recorded it, which is not the same as a sale worth zero.
            'deal_value' => $this->deal_value_minor === null ? null : $this->deal_value_minor / 100,
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
