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
        $ids = app(ProjectContext::class)->ids();

        if ($ids === null) {
            return;
        }

        $table = $model->getTable();
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
