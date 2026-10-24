<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the platform owner did, and to which company. Never shown inside a company's workspace: a company reads its own
 * trail (audit_logs); this one is the platform's.
 */
class PlatformAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['actor_id', 'tenant_id', 'action', 'subject_type', 'subject_id', 'changes', 'ip', 'user_agent'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class)->withTrashed();
    }
}
