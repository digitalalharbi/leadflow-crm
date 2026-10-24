<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per inbound touch. A returning phone number does not create a second
 * lead — it creates a source event against the existing lead and marks it re-engaged.
 */
class LeadSourceEvent extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'lead_id', 'provider', 'platform', 'source',
        'campaign_name', 'campaign_id', 'adset_name', 'ad_name', 'form_name', 'form_version_id',
        'provider_lead_id', 'was_duplicate', 'payload', 'received_at',
    ];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'was_duplicate' => 'boolean',
            'received_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
