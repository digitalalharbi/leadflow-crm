<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A property held for a lead: the step between a visit and a sale. */
class Reservation extends Model
{
    use BelongsToTenant, HasUuid;

    public const OPEN = ['pending', 'confirmed'];

    public const STATUSES = ['pending', 'confirmed', 'cancelled', 'expired', 'converted'];

    protected $fillable = [
        'lead_id', 'project_id', 'property_id', 'created_by', 'status', 'amount_minor',
        'reserved_at', 'expires_at', 'converted_at', 'cancelled_at', 'cancel_reason', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'reserved_at' => 'datetime',
            'expires_at' => 'datetime',
            'converted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class)->withTrashed();
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }
}
