<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use App\Models\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Appointment extends Model
{
    use BelongsToTenant, HasFactory, HasUuid, SoftDeletes;

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    protected $fillable = [
        'lead_id', 'assigned_to', 'created_by', 'scheduled_at', 'duration_minutes',
        'status', 'location', 'notes', 'outcome_note', 'external_provider', 'external_id',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'reminded_at' => 'datetime',
            'completed_at' => 'datetime',
            'duration_minutes' => 'integer',
        ];
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** Still going to happen. */
    public function scopeUpcoming($query)
    {
        return $query->where('status', 'scheduled')->where('scheduled_at', '>=', now());
    }

    /**
     * Scheduled, and the time has passed without anyone saying what happened.
     * This is the set a manager needs: a meeting nobody closed out is either a
     * sale nobody recorded or a customer nobody met.
     */
    public function scopeAwaitingOutcome($query)
    {
        return $query->where('status', 'scheduled')->where('scheduled_at', '<', now());
    }

    public function isPast(): bool
    {
        return $this->scheduled_at->isPast();
    }
}
