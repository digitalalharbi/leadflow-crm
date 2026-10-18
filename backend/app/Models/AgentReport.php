<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class AgentReport extends Model
{
    use BelongsToTenant, HasUuid;

    public const UPDATED_AT = null;

    protected $fillable = ['project_id', 'kind', 'period_from', 'period_to', 'data'];

    protected function casts(): array
    {
        return ['period_from' => 'date', 'period_to' => 'date', 'data' => 'array'];
    }
}
