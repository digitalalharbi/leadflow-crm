<?php

namespace App\Services\Leads;

use App\Domain\Leads\WorkloadBalancer;
use App\Enums\ActivityType;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\LeadAssignedNotification;
use Illuminate\Support\Collection;
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
    public function pickAssignee(Tenant $tenant, ?Lead $lead = null): ?User
    {
        $settings = $tenant->settings;

        if (! $settings || $settings->assignment_mode !== 'round_robin') {
            return null;
        }

        return $this->byStrategy($tenant, $lead);
    }

    /**
     * The workspace's chosen strategy, used wherever a lead needs an owner and
     * no particular person was named.
     */
    public function byStrategy(Tenant $tenant, ?Lead $lead = null): ?User
    {
        /*
         * Narrowing, not a list of competing strategies.
         *
         * Each step below answers "is there a smaller group that should have
         * this lead?" and gets out of the way when it has no opinion. A
         * workspace that has set none of them lands exactly where it did
         * before — whoever is least busy — which is the only honest default for
         * something that decides who talks to a customer.
         *
         * The order is deliberate. Knowing the project beats covering the city:
         * a rep on the النرجس team should get a النرجس lead even if somebody in
         * Riyadh is nominally closer to it, because what the customer asks
         * about is the project.
         */
        $candidates = $this->assignable($tenant);

        if ($candidates->isEmpty()) {
            return null;
        }

        $candidates = $this->whoKnowsTheProject($candidates, $lead) ?? $candidates;
        $candidates = $this->whoSellsThere($candidates, $lead) ?? $candidates;

        return match ($tenant->settings?->assignment_strategy) {
            'round_robin' => $this->rotateAmong($tenant, $candidates),
            // Workload is the default: taking turns lets one rep's backlog grow
            // while another sits idle, and keeps feeding leads to someone away.
            default => $this->workload->pick($tenant, $candidates),
        };
    }

    /**
     * Everyone who can take a lead right now, falling back to the whole team
     * when all of them are away — a lead that belongs to nobody is worse than
     * one waiting on a rep who is back on Sunday.
     *
     * @return Collection<int,User>
     */
    private function assignable(Tenant $tenant): Collection
    {
        $base = fn () => User::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('role', [Role::Sales->value, Role::Manager->value])
            ->orderBy('id');

        $available = $base()->available()->get();

        return $available->isNotEmpty() ? $available : $base()->where('is_active', true)->get();
    }

    /**
     * The project's own team, when the lead belongs to a project that has one.
     *
     * A developer selling three compounds does not have one sales team, it has
     * three: the rep who knows النرجس can answer what the service charge covers,
     * and the rep who does not has to go and ask.
     *
     * Null means no opinion — no project, no team, or nobody on it available —
     * and the lead falls through rather than being held.
     *
     * @param  Collection<int,User>  $candidates
     * @return Collection<int,User>|null
     */
    private function whoKnowsTheProject(Collection $candidates, ?Lead $lead): ?Collection
    {
        if (! $lead?->project_id) {
            return null;
        }

        $onProject = User::query()
            ->whereIn('id', $candidates->pluck('id'))
            ->whereHas('projects', fn ($q) => $q->where('projects.id', $lead->project_id))
            ->pluck('id')
            ->all();

        $team = $candidates->whereIn('id', $onProject)->values();

        return $team->isEmpty() ? null : $team;
    }

    /**
     * The reps who sell in the lead's city.
     *
     * Null when the lead has no city, or when nobody has said which cities they
     * cover, or when none of the remaining candidates covers it — a lead from a
     * city nobody claims still needs an owner, and giving it to somebody is
     * better than giving it to no one.
     *
     * @param  Collection<int,User>  $candidates
     * @return Collection<int,User>|null
     */
    private function whoSellsThere(Collection $candidates, ?Lead $lead): ?Collection
    {
        if (blank($lead?->city)) {
            return null;
        }

        $covering = $candidates->filter(fn (User $user) => $user->coversCity($lead->city))->values();

        /*
         * Everybody "covers" a city when nobody has set coverage, so this only
         * narrows anything once somebody has. Returning null in that case keeps
         * the next step working on the same set rather than a copy.
         */
        return $covering->isEmpty() || $covering->count() === $candidates->count()
            ? null
            : $covering;
    }

    /**
     * Takes turns, counting a rep with a weight of three as three places in the
     * rotation — so the weight means the same thing under either strategy.
     *
     * @param  Collection<int,User>  $candidates
     */
    private function rotateAmong(Tenant $tenant, Collection $candidates): ?User
    {
        $settings = $tenant->settings;

        if (! $settings || $candidates->isEmpty()) {
            return null;
        }

        $slots = $candidates->flatMap(
            fn (User $user) => array_fill(0, $user->assignmentWeight(), $user)
        )->values();

        // Advance the cursor atomically so two concurrent webhooks never pick the
        // same rep twice in a row.
        return DB::transaction(function () use ($slots, $settings) {
            $fresh = $settings->newQuery()->lockForUpdate()->find($settings->id);
            $index = $fresh->round_robin_cursor % $slots->count();
            $fresh->increment('round_robin_cursor');

            return $slots[$index];
        });
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
