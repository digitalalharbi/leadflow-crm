<?php

namespace App\Domain\Leads;

use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Picks the rep who should get the next lead by how much they are already
 * carrying, rather than by whose turn it is.
 *
 * Round robin is fair only if every lead costs the same and every rep works at
 * the same pace, and neither is true: a rep holding forty open leads and one
 * holding four take turns, so the backlog never clears. It also keeps handing
 * leads to someone who is away, which is how a lead sits untouched for a day
 * inside a system whose whole promise is that it will not.
 *
 * "Open" excludes closed leads on purpose: a rep who closed thirty deals this
 * month must not be penalised for having worked.
 */
class WorkloadBalancer
{
    /**
     * The least-loaded active rep, or null when the workspace has none.
     *
     * Ties break on whoever has gone longest without a lead, so two idle reps
     * alternate instead of one taking everything.
     */
    public function pick(Tenant $tenant, ?Collection $only = null): ?User
    {
        $candidates = $only ?? $this->candidates($tenant);

        if ($candidates->isEmpty()) {
            return null;
        }

        $ids = $candidates->pluck('id')->all();
        $open = $this->openCounts($tenant, $ids);
        $last = $this->lastAssignedAt($tenant, $ids);

        /*
         * One comparator rather than a multi-key sort: `sortBy` with an array
         * of closures does not order by them in sequence, which silently
         * returned the busiest rep instead of the freest one.
         *
         * Fewest open leads wins. Ties go to whoever has waited longest, with
         * "never assigned" sorting first — an empty string is less than any
         * timestamp.
         */
        return $candidates
            ->sort(function (User $a, User $b) use ($open, $last) {
                $load = ($open[$a->id] ?? 0) <=> ($open[$b->id] ?? 0);

                if ($load !== 0) {
                    return $load;
                }

                return ($last[$a->id] ?? '') <=> ($last[$b->id] ?? '');
            })
            ->first();
    }

    /** @return Collection<int,User> */
    /**
     * Whoever can take a lead right now.
     *
     * `available()` rather than `is_active`: the second is an account state,
     * set when somebody leaves the company. A rep away until Sunday is active
     * and cannot work a lead, and sending them one is how a lead sits untouched
     * for a day inside a system whose whole promise is that it will not.
     *
     * Falls back to everyone when the whole team is away, because an unassigned
     * lead is worse than one waiting on somebody who is back on Sunday — it
     * belongs to nobody, appears on nobody's queue, and is found by whoever next
     * reads the data-quality screen.
     */
    private function candidates(Tenant $tenant): Collection
    {
        $base = fn () => User::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('role', [Role::Sales->value, Role::Manager->value])
            ->orderBy('id');

        $available = $base()->available()->get();

        return $available->isNotEmpty()
            ? $available
            : $base()->where('is_active', true)->get();
    }

    /**
     * One grouped query, not one per rep: this runs on the ingestion path, and
     * a count per salesperson would make every webhook slower as the team
     * grows.
     *
     * The tenant is named here rather than inherited from the global scope,
     * which fails closed — correct for a request, wrong for a queued job or a
     * scheduled sweep, where it would silently count zero leads for everyone
     * and hand the next lead to whoever happens to sort first.
     *
     * @param  array<int,int>  $userIds
     * @return array<int,int>
     */
    private function openCounts(Tenant $tenant, array $userIds): array
    {
        $closed = array_map(
            fn (LeadStatus $s) => $s->value,
            array_values(array_filter(LeadStatus::cases(), fn (LeadStatus $s) => $s->isClosed())),
        );

        return Lead::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->whereIn('assigned_to', $userIds)
            ->whereNotIn('status', $closed)
            ->selectRaw('assigned_to, count(*) as aggregate')
            ->groupBy('assigned_to')
            ->pluck('aggregate', 'assigned_to')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * @param  array<int,int>  $userIds
     * @return array<int,string>
     */
    private function lastAssignedAt(Tenant $tenant, array $userIds): array
    {
        return Lead::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->whereIn('assigned_to', $userIds)
            ->selectRaw('assigned_to, max(updated_at) as last_at')
            ->groupBy('assigned_to')
            ->pluck('last_at', 'assigned_to')
            ->map(fn ($at) => (string) $at)
            ->all();
    }
}
