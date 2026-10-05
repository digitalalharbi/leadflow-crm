<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Keeps the lead created to prove the setup works out of every number that
 * describes the business.
 *
 * Applied globally, like the tenant scope, and for the same reason: a rule that
 * each query has to remember is a rule that one query will forget, and the
 * symptom — a conversion rate off by one lead — is invisible until somebody
 * reconciles two screens by hand.
 *
 * `Lead::withTestLeads()` opts back in. Exactly one screen does.
 */
class ExcludesTestLeads implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->getTable().'.is_test', false);
    }
}
