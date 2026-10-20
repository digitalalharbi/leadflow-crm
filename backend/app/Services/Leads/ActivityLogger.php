<?php

namespace App\Services\Leads;

use App\Domain\Automation\FollowUpPlanner;
use App\Domain\Automation\HumanTakeover;
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
        $activity = LeadActivity::create([
            'lead_id' => $lead->id,
            'user_id' => $actor?->id,
            'type' => $type,
            'description' => $description,
            'meta' => $meta ?: null,
        ]);

        // A person making contact takes the lead from automation; a person
        // recording that nobody answered gives it back. Rules log with no actor.
        if ($actor !== null) {
            $takeover = app(HumanTakeover::class);

            match (true) {
                in_array($type, [ActivityType::Called, ActivityType::WhatsappSent, ActivityType::EmailSent, ActivityType::WhatsappClicked], true) => $takeover->tookOver($lead),
                $type === ActivityType::NoAnswer => $takeover->noResponse($lead),
                $type === ActivityType::StatusChanged && ($meta['to'] ?? null) === 'no_response' => $takeover->noResponse($lead),
                default => null,
            };
        }

        // A booked visit ends the automatic follow-up: the rep has it from here.
        if ($type === ActivityType::AppointmentCreated) {
            try {
                app(FollowUpPlanner::class)->appointmentBooked($lead);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $activity;
    }
}
