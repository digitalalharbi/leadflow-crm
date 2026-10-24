<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What happened to a report, and who did it. */
class ReportActivity extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const ACTIONS = ['created', 'edited', 'published', 'shared', 'viewed', 'exported', 'password_changed', 'token_regenerated', 'revoked', 'archived', 'unarchived', 'duplicated', 'note_added', 'link_created'];

    protected $table = 'report_activities';

    protected $fillable = ['tenant_id', 'report_share_id', 'action', 'actor_id', 'meta'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
