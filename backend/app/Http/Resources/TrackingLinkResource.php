<?php

namespace App\Http\Resources;

use App\Models\TrackingLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TrackingLink */
class TrackingLinkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'token' => $this->token,
            'url' => $this->url(),
            'name' => $this->name,
            'kind' => $this->kind,
            'channel' => $this->channel,
            'project' => $this->whenLoaded('project', fn () => $this->project ? ['id' => $this->project->uuid, 'name' => $this->project->name] : null),
            'property' => $this->whenLoaded('property', fn () => $this->property ? ['id' => $this->property->uuid, 'code' => $this->property->code] : null),
            'campaign' => $this->whenLoaded('campaign', fn () => $this->campaign ? ['id' => $this->campaign->uuid, 'name' => $this->campaign->name] : null),
            'ad' => $this->whenLoaded('ad', fn () => $this->ad ? ['id' => $this->ad->uuid, 'name' => $this->ad->name] : null),
            'creative' => $this->whenLoaded('creative', fn () => $this->creative ? ['id' => $this->creative->uuid, 'name' => $this->creative->name] : null),
            'rep' => $this->whenLoaded('rep', fn () => $this->rep ? ['id' => $this->rep->uuid, 'name' => $this->rep->name] : null),
            'partner_id' => $this->partner_id,
            'landing_page' => $this->landing_page,
            'branch' => $this->branch,
            'destination_url' => $this->destination_url,
            'utm' => array_filter([
                'source' => $this->utm_source, 'medium' => $this->utm_medium, 'campaign' => $this->utm_campaign,
                'term' => $this->utm_term, 'content' => $this->utm_content,
            ], fn ($v) => filled($v)),
            'is_active' => (bool) $this->is_active,
            'is_live' => $this->isLive(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
