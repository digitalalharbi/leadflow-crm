<?php

namespace App\Models\Scopes;

use App\Models\Project;
use App\Support\ProjectContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Narrows everything that belongs to a project to the projects this person was given.
 *
 * A global scope, like the tenant's and the company's, so it holds in a list, a report, a search,
 * a count and an export alike — there is no query that can forget it. Inert unless the request is
 * from a project-scoped role. Leads, campaigns, properties and links carry a project; visits,
 * follow-ups and reservations are reached through their lead. A lead with no project is outside
 * every scope: nobody can be given "no project".
 */
class ProjectScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(ProjectContext::class);
        $ids = $context->ids();
        $partner = $context->partnerId();
        $table = $model->getTable();

        // A broker sees what their own partner brought: their leads, and what hangs off them.
        if ($partner !== null) {
            if ($table === 'leads') {
                $builder->where('leads.partner_id', $partner);
            } elseif ($table === 'tracking_links') {
                // Their own links, wherever they point: what is theirs is decided by the partner, not the project.
                $builder->where('tracking_links.partner_id', $partner);

                return;
            } elseif (in_array($table, ['appointments', 'follow_ups', 'reservations'], true)) {
                $builder->whereIn("{$table}.lead_id", fn ($q) => $q->select('leads.id')->from('leads')->where('leads.partner_id', $partner));
            }
        }

        // A rep opens the projects they work on — the ones given to them and the ones their leads belong to — and no other.
        if ($model instanceof Project && $context->listed() !== null) {
            $builder->whereIn("{$table}.id", $context->listed() ?: [-1]);
        }

        if ($ids === null) {
            return;
        }

        $ids = $ids ?: [-1];

        if ($model instanceof Project) {
            $builder->whereIn("{$table}.id", $ids);

            return;
        }

        if (in_array($table, ['leads', 'campaigns', 'properties', 'tracking_links'], true)) {
            $builder->whereIn("{$table}.project_id", $ids);

            return;
        }

        // Visits, follow-ups, reservations: through the lead they are about.
        $builder->whereIn("{$table}.lead_id", fn ($q) => $q->select('leads.id')->from('leads')->whereIn('leads.project_id', $ids));
    }
}
