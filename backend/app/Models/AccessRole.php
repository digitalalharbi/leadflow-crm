<?php

namespace App\Models;

use App\Domain\Access\AccessResolver;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** A role a workspace built for itself: a name, what it may do, and which built-in role it behaves like. */
class AccessRole extends Model
{
    use BelongsToTenant, HasUuid;

    protected static function booted(): void
    {
        // What was resolved from the old rules is wrong the moment they change.
        $flush = fn () => AccessResolver::flush();
        static::saved($flush);
        static::deleted($flush);
    }

    protected $fillable = ['key', 'name', 'description', 'base_role', 'project_scoped', 'external', 'grants', 'created_by'];

    protected function casts(): array
    {
        return ['grants' => 'array', 'project_scoped' => 'boolean', 'external' => 'boolean'];
    }
}
