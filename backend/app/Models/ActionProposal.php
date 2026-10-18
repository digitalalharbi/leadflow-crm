<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A change somebody wants made to an ad account, waiting for a person's decision. */
class ActionProposal extends Model
{
    use BelongsToTenant, HasUuid;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const EXECUTING = 'executing';

    public const EXECUTED = 'executed';

    public const FAILED = 'failed';

    public const BLOCKED = 'blocked';

    protected $fillable = [
        'project_id', 'campaign_id', 'ad_set_id', 'ad_id', 'platform', 'action', 'payload', 'reason',
        'expected_impact', 'proposed_by', 'agent', 'proposed_by_user_id', 'status', 'policy',
        'decided_by', 'decided_at', 'decision_note', 'idempotency_key', 'executed_at', 'error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'policy' => 'array',
            'decided_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function adSet(): BelongsTo
    {
        return $this->belongsTo(AdSet::class);
    }

    public function ad(): BelongsTo
    {
        return $this->belongsTo(Ad::class);
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by_user_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function log(): HasMany
    {
        return $this->hasMany(ActionLogEntry::class);
    }
}
