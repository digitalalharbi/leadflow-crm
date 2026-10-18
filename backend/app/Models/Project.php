<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use App\Models\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use BelongsToTenant, HasFactory, HasUuid, SoftDeletes;

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    protected $fillable = [
        'company_id', 'name', 'developer_name', 'location', 'starting_price', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return ['starting_price' => 'decimal:2'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /**
     * The reps who work this project.
     *
     * Empty means anybody, which is what every workspace starts as — nothing
     * about assignment changes until somebody says a project has a team.
     */
    public function team(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
