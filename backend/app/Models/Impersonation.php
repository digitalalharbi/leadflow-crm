<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One support session: the platform owner working inside a company as one of its people, for a stated reason, for a
 * limited time. Platform-level — it carries the company's id but is read by the platform (and summarised to the
 * company in its own trail).
 */
class Impersonation extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $fillable = ['platform_user_id', 'tenant_id', 'target_user_id', 'token_id', 'reason', 'ip', 'started_at', 'expires_at', 'ended_at', 'ended_by'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'expires_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function platformUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'platform_user_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isLive(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }
}
