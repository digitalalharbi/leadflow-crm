<?php

namespace App\Services\Leads;

use App\Enums\ActivityType;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;

class ActivityLogger
{
    public function log(
        Lead $lead,
        ActivityType $type,
        ?User $actor = null,
        ?string $description = null,
        array $meta = [],
    ): LeadActivity {
        return LeadActivity::create([
            'lead_id' => $lead->id,
            'user_id' => $actor?->id,
            'type' => $type,
            'description' => $description,
            'meta' => $meta ?: null,
        ]);
    }
}
