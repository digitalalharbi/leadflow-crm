<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use App\Models\Scopes\ProjectScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One thing that can be sold or rented inside a project: a flat, a villa, a plot.
 *
 * Price is in minor units like a deal's value. `status` says whether it can still be offered —
 * a reservation moves it to reserved, a won deal to sold — so the matching engine never
 * recommends what is gone.
 */
class Property extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new ProjectScope);
    }

    use BelongsToTenant, HasUuid, SoftDeletes;

    protected $fillable = [
        'project_id', 'code', 'title', 'property_type', 'transaction_type', 'price_minor',
        'city', 'district', 'bedrooms', 'bathrooms', 'area_sqm', 'floor', 'status', 'features', 'notes',
    ];

    /** The database defaults, so a model that was just created says the same as one read back. */
    protected $attributes = ['status' => 'available', 'transaction_type' => 'sale'];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'bedrooms' => 'integer',
            'bathrooms' => 'integer',
            'floor' => 'integer',
            'area_sqm' => 'decimal:2',
            'features' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /** Major units for the screen. */
    public function price(): ?float
    {
        return $this->price_minor === null ? null : $this->price_minor / 100;
    }

    public function isAvailable(): bool
    {
        return $this->status === 'available';
    }
}
