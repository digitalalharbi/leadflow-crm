<?php

namespace App\Services\Leads;

use App\Domain\Leads\WorkloadBalancer;
use App\Enums\ActivityType;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\LeadAssignedNotification;
use Illuminate\Support\Facades\DB;

class LeadAssignmentService
{
    public function __construct(
        private ActivityLogger $activities,
        private WorkloadBalancer $workload,
    ) {}

    /**
     * Pick the next sales user for a tenant. Manual mode returns null — the lead
     * lands unassigned in the queue and a manager distributes it.
     */
    /**
     * The tenant's default policy for a lead arriving with nobody named on it.
     * Honours `assignment_mode`, so a workspace that wants to triage by hand
     * keeps doing that.
     */
    public function pickAssignee(Tenant $tenant): ?User
    {
        $settings = $tenant->settings;

        if (! $settings || $settings->assignment_mode !== 'round_robin') {
            return null;
        }

        return $this->byStrategy($tenant);
    }

    /**
     * The workspace's chosen strategy, used wherever a lead needs an owner and
     * no particular person was named.
     */
    public function byStrategy(Tenant $tenant): ?User
    {
        return match ($tenant->settings?->assignment_strategy) {
            'round_robin' => $this->roundRobin($tenant),
            // Workload is the default: taking turns lets one rep's backlog grow
            // while another sits idle, and keeps feeding leads to someone away.
            default => $this->workload->pick($tenant) ?? $this->roundRobin($tenant),
        };
    }

    /**
     * Round robin regardless of the tenant's default mode.
     *
     * An automation rule that says "assign" is an instruction, not a
     * preference: letting `assignment_mode: manual` veto it would mean a rule
     * the tenant wrote themselves silently does nothing, which is the worst
     * failure an automation product has.
     */
    public function roundRobin(Tenant $tenant): ?User
    {
        $settings = $tenant->settings;

        if (! $settings) {
            return null;
        }

        /*
         * Whoever can take a lead right now, falling back to the whole team
         * when everybody is away — an unassigned lead belongs to nobody and
         * appears on nobody's queue, which is worse than one waiting for a rep
         * who is back on Sunday.
         */
        $base = fn () => User::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('role', [Role::Sales->value, Role::Manager->value])
            ->orderBy('id');

        $candidates = $base()->available()->get();

        if ($candidates->isEmpty()) {
            $candidates = $base()->where('is_active', true)->get();
        }

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
