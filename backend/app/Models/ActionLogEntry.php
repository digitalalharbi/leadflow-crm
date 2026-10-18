<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** What was sent to a platform and what came back. Append-only. */
class ActionLogEntry extends Model
{
    use BelongsToTenant;

    protected $table = 'action_log';

    public const UPDATED_AT = null;

    protected $fillable = ['action_proposal_id', 'platform', 'action', 'request', 'response', 'ok', 'executed_by', 'duration_ms'];

    protected function casts(): array
    {
        return ['request' => 'array', 'response' => 'array', 'ok' => 'boolean'];
    }

    protected static function booted(): void
    {
        // The audit trail is only worth anything if nobody can edit it.
        static::updating(fn () => throw new LogicException('The action log is append-only.'));
        static::deleting(fn () => throw new LogicException('The action log is append-only.'));
    }
}
