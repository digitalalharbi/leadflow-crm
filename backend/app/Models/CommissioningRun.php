<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** One commissioning attempt for one integration. See the migration. */
class CommissioningRun extends Model
{
    use BelongsToTenant;

    protected $fillable = ['ran_by', 'key', 'ok', 'steps'];

    protected function casts(): array
    {
        return ['ok' => 'boolean', 'steps' => 'array'];
    }
}
