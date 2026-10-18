<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use App\Models\Scopes\CompanyScope;
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

    /**
     * The lead's `next_follow_up_at` is derived, not remembered.
     *
     * Seven places write follow-ups and three of them also set the column by
     * hand, so the one that mattered most did not: a follow-up booked by the
     * rep's own outcome in the work queue left the lead with no scheduled step
     * at all. Nothing looked wrong — the row existed, the follow-ups screen
     * showed it — but everything that reads the column was blind to it.
     *
     * The rep paid for that three times over. The work queue sorts by it, so
     * the lead sorted as if nothing were booked; the overdue count on the rep's
     * own screen is `count(*) filter (where next_follow_up_at < now())`, so
     * their own follow-ups were never counted; and `NextBestAction` only says
     * "wait" when the column is set — so after recording "لم يرد", under a
     * message promising a reminder in three hours, the queue handed the same
     * lead straight back.
     *
     * Derived here rather than at each call site, for the same reason the lead
     * invariants live on that model: the next place somebody books a follow-up
     * is a place that would have to remember.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::saved(fn (self $followUp) => $followUp->syncLeadSchedule());
        static::deleted(fn (self $followUp) => $followUp->syncLeadSchedule());
    }

    /**
     * Without global scopes: this runs from queued jobs and console commands
     * where there is no request to resolve a tenant from, and a scope that
     * fails closed would quietly derive the column from no rows at all. A lead
     * id identifies one lead across the whole database, so nothing here can
     * reach into another workspace.
     */
    public function syncLeadSchedule(): void
    {
        $lead = Lead::withoutGlobalScopes()->find($this->lead_id);

        if (! $lead) {
            return;
        }

        $next = static::withoutGlobalScopes()
            ->where('lead_id', $this->lead_id)
            ->where('status', self::PENDING)
            ->min('due_at');

        if ($lead->next_follow_up_at?->toDateTimeString() === $next) {
            return;
        }

        // Quietly: this is bookkeeping about the lead, not a change to it, and
        // a touch here would announce a lead event for every reminder booked.
        $lead->forceFill(['next_follow_up_at' => $next])->saveQuietly();
    }

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
