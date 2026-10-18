<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entity's numbers for one day, in minor units. See the migration for why
 * `level` matters: roll-ups sum every row of a campaign.
 */
class MetricSnapshot extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'project_id', 'ad_account_id', 'platform', 'level', 'campaign_id', 'ad_set_id', 'ad_id',
        'date', 'spend_minor', 'impressions', 'clicks', 'leads', 'purchases', 'revenue_minor',
        'currency', 'raw',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'spend_minor' => 'integer',
            'impressions' => 'integer',
            'clicks' => 'integer',
            'leads' => 'integer',
            'purchases' => 'integer',
            'revenue_minor' => 'integer',
            'raw' => 'array',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
