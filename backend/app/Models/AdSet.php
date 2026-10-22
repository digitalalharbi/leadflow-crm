<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdSet extends Model
{
    use BelongsToTenant, HasUuid;

    protected $fillable = ['campaign_id', 'external_id', 'name', 'status', 'daily_budget_minor', 'targeting', 'archived_at'];

    protected function casts(): array
    {
        return ['daily_budget_minor' => 'integer', 'targeting' => 'array', 'archived_at' => 'datetime'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function ads(): HasMany
    {
        return $this->hasMany(Ad::class);
    }
}
