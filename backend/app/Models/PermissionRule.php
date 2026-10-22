<?php

namespace App\Models;

use App\Domain\Access\AccessResolver;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** One change a workspace made to the access it was given: allow or deny, at one layer. */
class PermissionRule extends Model
{
    use BelongsToTenant;

    public const LAYERS = ['workspace', 'role', 'project', 'user'];

    protected static function booted(): void
    {
        // What was resolved from the old rules is wrong the moment they change.
        $flush = fn () => AccessResolver::flush();
        static::saved($flush);
        static::deleted($flush);
    }

    protected $fillable = ['layer', 'role_key', 'project_id', 'user_id', 'permission', 'effect', 'created_by'];
}
