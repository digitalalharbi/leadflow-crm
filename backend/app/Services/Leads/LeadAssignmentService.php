<?php

namespace App\Services\Leads;

use App\Enums\ActivityType;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\LeadAssignedNotification;
use Illuminate\Support\Facades\DB;

class LeadAssignmentService
{
    public function __construct(private ActivityLogger $activities) {}

    /**
     * Pick the next sales user for a tenant. Manual mode returns null — the lead
     * lands unassigned in the queue and a manager distributes it.
     */
    public function pickAssignee(Tenant $tenant): ?User
    {
        $settings = $tenant->settings;

        if (! $settings || $settings->assignment_mode !== 'round_robin') {
            return null;
        }

        $candidates = User::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->whereIn('role', [Role::Sales->value, Role::Manager->value])
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        // Advance the cursor atomically so two concurrent webhooks never pick the
        // same rep twice in a row.
        return DB::transaction(function () use ($candidates, $settings) {
            $fresh = $settings->newQuery()->lockForUpdate()->find($settings->id);
            $index = $fresh->round_robin_cursor % $candidates->count();
            $fresh->increment('round_robin_cursor');

            return $candidates[$index];
        });
    }

    public function assign(Lead $lead, ?User $assignee, ?User $actor = null, bool $notify = true): Lead
    {
        $previous = $lead->assigned_to;

        $lead->assigned_to = $assignee?->id;
        $lead->assigned_at = $assignee ? now() : null;
        $lead->save();

        if ($assignee && $previous !== $assignee->id) {
            $this->activities->log(
                $lead,
                ActivityType::Assigned,
                $actor,
                null,
                ['assigned_to' => $assignee->id, 'assigned_to_name' => $assignee->name, 'from' => $previous],
            );

            if ($notify) {
                $assignee->notify(new LeadAssignedNotification($lead));
            }
        }

        return $lead;
    }
}
