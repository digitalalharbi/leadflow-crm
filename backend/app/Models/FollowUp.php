<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FollowUp extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    public const PENDING = 'pending';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'lead_id', 'assigned_to', 'created_by', 'due_at',
        'note', 'status', 'completed_at', 'outcome', 'outcome_note',
    ];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->pending()->where('due_at', '<', now());
    }

    /**
     * Still to do today.
     *
     * Deliberately excludes anything already past: the three buckets must be
     * disjoint, or a rep sees the same follow-up counted twice and the numbers
     * stop adding up to the work in front of them.
     */
    public function scopeDueToday(Builder $query): Builder
    {
        return $query->pending()->whereBetween('due_at', [now(), now()->endOfDay()]);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->pending()->where('due_at', '>', now()->endOfDay());
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->seesAllLeads()) {
            return $query;
        }

        return $query->where('follow_ups.assigned_to', $user->id);
    }
}
