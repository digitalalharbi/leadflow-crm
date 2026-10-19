<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing automation did, is waiting to do, or decided not to — the row the
 * Action History shows. Written by the engine for every action of every rule,
 * and updated when a waiting step finally runs, so a manager reading it sees
 * "10:32 أحمد · ترحيب · واتساب · أُرسل" rather than reconstructing it.
 */
class AutomationActionLog extends Model
{
    use BelongsToTenant, HasUuid;

    public const COMPLETED = 'completed';

    public const WAITING = 'waiting';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    public const NEEDS_HUMAN = 'needs_human';

    public const RESULTS = [self::COMPLETED, self::WAITING, self::FAILED, self::SKIPPED, self::NEEDS_HUMAN];

    protected $fillable = [
        'lead_id', 'automation_rule_id', 'scheduled_action_id', 'rule_name', 'trigger', 'action_type',
        'channel', 'mode', 'result', 'before', 'after', 'description', 'happened_at',
    ];

    protected function casts(): array
    {
        return ['happened_at' => 'datetime'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    /** Which channel an action speaks on, for the history's channel column. */
    public static function channelFor(array $action): ?string
    {
        return match ($action['type'] ?? null) {
            'send_whatsapp', 'ask_qualification', 'request_appointment', 'start_nurture' => 'whatsapp',
            'send_email' => 'email',
            'share_file' => ($action['channel'] ?? 'whatsapp') === 'email' ? 'email' : 'whatsapp',
            'notify', 'hand_off' => 'team',
            'create_call_task' => 'call',
            default => null,
        };
    }
}
