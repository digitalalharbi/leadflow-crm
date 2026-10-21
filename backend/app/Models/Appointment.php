<?php

namespace App\Models;

use App\Domain\Leads\InterestEngine;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use App\Models\Scopes\CompanyScope;
use App\Models\Scopes\ProjectScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Appointment extends Model
{
    use BelongsToTenant, HasFactory, HasUuid, SoftDeletes;

    protected static function booted(): void
    {
        static::addGlobalScope(new ProjectScope);

        static::addGlobalScope(new CompanyScope);

        // A visit booked or done changes how interested the lead is.
        static::saved(fn (self $a) => InterestEngine::touch($a->lead_id, 'appointment'));
    }

    public const REQUESTED = 'requested';

    public const CONFIRMED = 'confirmed';

    public const COMPLETED = 'completed';

    public const NO_SHOW = 'no_show';

    public const RESCHEDULED = 'rescheduled';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::REQUESTED, self::CONFIRMED, self::COMPLETED, self::NO_SHOW, self::RESCHEDULED, self::CANCELLED];

    /**
     * Still going to happen (or waiting for somebody to say when). `scheduled` is what a
     * confirmed visit used to be called; rows and callers that still say it are not stranded.
     */
    public const LIVE = [self::REQUESTED, self::CONFIRMED, self::RESCHEDULED, 'scheduled'];

    /** With a time in the calendar. */
    public const BOOKED = [self::CONFIRMED, self::RESCHEDULED, 'scheduled'];

    /** What came of a visit that happened. */
    public const OUTCOMES = ['interested', 'follow_up', 'reservation', 'lost'];

    protected $fillable = [
        'lead_id', 'project_id', 'property_id', 'assigned_to', 'created_by', 'scheduled_at', 'duration_minutes',
        'status', 'source', 'location', 'notes', 'outcome_note', 'external_provider', 'external_id',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'requested_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'no_show_at' => 'datetime',
            'outcome_at' => 'datetime',
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
        return $query->whereIn('status', self::BOOKED)->where('scheduled_at', '>=', now());
    }

    /**
     * Scheduled, and the time has passed without anyone saying what happened.
     * This is the set a manager needs: a meeting nobody closed out is either a
     * sale nobody recorded or a customer nobody met.
     */
    public function scopeAwaitingOutcome($query)
    {
        return $query->whereIn('status', self::BOOKED)->where('scheduled_at', '<', now());
    }

    /** Asked for by the customer, no time agreed yet. */
    public function scopeRequested($query)
    {
        return $query->where('status', self::REQUESTED);
    }

    public function isPast(): bool
    {
        return $this->scheduled_at !== null && $this->scheduled_at->isPast();
    }

    public function project()
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function property()
    {
        return $this->belongsTo(Property::class)->withTrashed();
    }
}
