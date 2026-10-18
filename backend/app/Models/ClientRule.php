<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** What a client wants and will not tolerate. Read by every agent, enforced by the gateway. */
class ClientRule extends Model
{
    use BelongsToTenant, HasUuid;

    protected $fillable = [
        'project_id', 'target_cpl_minor', 'target_cpql_minor', 'target_cpa_minor', 'target_roas',
        'min_spend_to_judge_minor', 'daily_budget_ceiling_minor', 'max_budget_change_pct',
        'blackout_days', 'forbidden_words', 'notes', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'target_cpl_minor' => 'integer',
            'target_cpql_minor' => 'integer',
            'target_cpa_minor' => 'integer',
            'target_roas' => 'float',
            'min_spend_to_judge_minor' => 'integer',
            'daily_budget_ceiling_minor' => 'integer',
            'max_budget_change_pct' => 'integer',
            'blackout_days' => 'array',
            'forbidden_words' => 'array',
        ];
    }

    /**
     * The rules that apply to a project: its own row, else the workspace's,
     * else an unsaved row of defaults — so a caller never has to ask whether
     * rules exist, and the defaults (20% max change) are always in force.
     */
    public static function for(?int $projectId): self
    {
        return ($projectId ? static::query()->where('project_id', $projectId)->first() : null)
            ?? static::query()->whereNull('project_id')->first()
            ?? new static(['max_budget_change_pct' => 20]);
    }
}
