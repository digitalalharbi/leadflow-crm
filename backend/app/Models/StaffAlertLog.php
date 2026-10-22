<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** One WhatsApp alert to a member of staff, and what became of it. */
class StaffAlertLog extends Model
{
    use BelongsToTenant, HasUuid;

    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    protected $fillable = ['user_id', 'category', 'dedup_key', 'title', 'status', 'reason', 'provider', 'provider_message_id', 'error', 'attempts', 'sent_at'];

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'sent_at' => 'datetime'];
    }
}
