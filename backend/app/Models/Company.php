<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A business inside the holding.
 *
 * Deliberately thin: a name, a colour, who works in it, and the projects it
 * owns. Everything else about a company — its leads, its numbers — is read
 * through its projects, so there is one place a lead's company is decided.
 */
class Company extends Model
{
    use BelongsToTenant, HasUuid, SoftDeletes;

    protected $fillable = ['name', 'description', 'color', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
