<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class Routine extends Model
{
    use BelongsToTenant, HasUuid;

    protected $fillable = ['key', 'agent', 'cron', 'is_active', 'last_run_at', 'last_status', 'last_summary'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'last_run_at' => 'datetime'];
    }
}
