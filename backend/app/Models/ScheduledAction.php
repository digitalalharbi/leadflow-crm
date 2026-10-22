<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An action a rule asked for later.
 *
 * Kept as a row rather than only as a queued job, for three reasons a job alone
 * cannot serve: the trace has to show what is still coming, closing a lead has
 * to be able to cancel it, and a worker that dies must not lose it.
 */
class ScheduledAction extends Model
{
    use BelongsToTenant, HasUuid;

    public const PENDING = 'pending';

    public const DONE = 'done';

    public const CANCELLED = 'cancelled';

    public const FAILED = 'failed';

    protected $fillable = [
        'lead_id', 'automation_rule_id', 'rule_name', 'action',
        'run_at', 'status', 'outcome', 'resolved_at', 'correlation_id',
    ];

    protected function casts(): array
    {
        return [
            'action' => 'array',
            'run_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    /** Written quietly: this is bookkeeping about the action, not about the lead. */
    public function resolve(string $status, ?string $outcome = null): void
    {
        $this->forceFill([
            'status' => $status,
            'outcome' => $outcome,
            'resolved_at' => now(),
        ])->saveQuietly();
    }
}
