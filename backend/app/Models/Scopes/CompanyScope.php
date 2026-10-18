<?php

namespace App\Models\Scopes;

use App\Models\Project;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Narrows a model to the company the request is looking at.
 *
 * A global scope for the same reason the tenant scope is one: a filter each
 * query has to remember is a filter one query will forget, and the symptom is
 * a number on one screen that disagrees with the same number on another.
 *
 * Inert unless the request has a filter. Leads, appointments and follow-ups
 * are reached *through their project*, so there is exactly one rule for which
 * company a lead belongs to; a lead with no project belongs to no company and
 * is outside any company's view.
 */
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $ids = app(CompanyContext::class)->ids();

        if ($ids === null) {
            return;
        }

        $table = $model->getTable();

        if ($model instanceof Project) {
            $builder->whereIn($table.'.company_id', $ids ?: [-1]);

            return;
        }

        // Appointments and follow-ups carry a lead, not a project.
        $column = $model->getTable() === 'leads' ? $table.'.project_id' : null;

        if ($column === null) {
            $builder->whereIn($table.'.lead_id', function ($query) use ($ids) {
                $query->select('leads.id')->from('leads')->whereIn('leads.project_id', function ($sub) use ($ids) {
                    $sub->select('projects.id')->from('projects')->whereIn('projects.company_id', $ids ?: [-1]);
                });
            });

            return;
        }

        $builder->whereIn($column, function ($query) use ($ids) {
            $query->select('projects.id')->from('projects')->whereIn('projects.company_id', $ids ?: [-1]);
        });
    }
}
