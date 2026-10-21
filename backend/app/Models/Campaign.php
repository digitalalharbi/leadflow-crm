<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use App\Models\Scopes\CompanyScope;
use App\Models\Scopes\ProjectScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A campaign, as the platform knows it or as its leads reveal it.
 *
 * Never deleted. `archived_at` says it is no longer on the platform; its leads,
 * its history and the sales it produced stay true.
 */
class Campaign extends Model
{
    use BelongsToTenant, HasUuid;

    protected static function booted(): void
    {
        static::addGlobalScope(new ProjectScope);

        // Reached through its project, like leads: one rule for which company it belongs to.
        static::addGlobalScope(new CompanyScope);
    }

    public const ORIGIN_SYNC = 'sync';

    public const ORIGIN_ATTRIBUTION = 'lead_attribution';

    public const ORIGIN_MANUAL = 'manual';

    protected $fillable = [
        'project_id', 'ad_account_id', 'platform', 'external_id', 'name', 'origin',
        'objective', 'status', 'daily_budget_minor', 'lifetime_budget_minor', 'currency',
        'starts_at', 'ends_at', 'last_synced_at', 'archived_at', 'raw',
        'quality_score', 'quality_previous', 'quality_scored_at',
    ];

    protected function casts(): array
    {
        return [
            'daily_budget_minor' => 'integer',
            'lifetime_budget_minor' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'archived_at' => 'datetime',
            'raw' => 'array',
            'quality_score' => 'integer',
            'quality_previous' => 'integer',
            'quality_scored_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AdAccount::class, 'ad_account_id');
    }

    public function adSets(): HasMany
    {
        return $this->hasMany(AdSet::class);
    }

    public function ads(): HasMany
    {
        return $this->hasMany(Ad::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'campaign_ref_id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(MetricSnapshot::class);
    }
}
