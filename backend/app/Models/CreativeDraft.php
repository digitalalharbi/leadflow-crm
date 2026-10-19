<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreativeDraft extends Model
{
    use BelongsToTenant, HasUuid;

    protected $fillable = ['ad_id', 'kind', 'items', 'rationale', 'source', 'agent', 'created_by', 'status', 'flags'];

    protected function casts(): array
    {
        return ['items' => 'array', 'flags' => 'array'];
    }

    public function ad(): BelongsTo
    {
        return $this->belongsTo(Ad::class);
    }
}
