<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One tenant's "when this happens, do that".
 *
 * Soft-deleted rather than removed: a trace from last month refers to the rule
 * that ran, and a hard delete would turn that into "rule #12, which no longer
 * exists" exactly when someone is trying to understand what the system did.
 */
class AutomationRule extends Model
{
    use BelongsToTenant, HasUuid, SoftDeletes;

    /** Do it. */
    public const MODE_AUTO = 'auto';

    /** Tell the lead's owner what it would do, and let them decide. */
    public const MODE_RECOMMEND = 'recommend';

    /** Only write down what it would have done. */
    public const MODE_MONITOR = 'monitor';

    public const MODES = [self::MODE_AUTO, self::MODE_RECOMMEND, self::MODE_MONITOR];

    protected $fillable = [
        'name', 'trigger', 'template_key', 'is_enabled', 'mode', 'priority',
        'stops_processing', 'conditions', 'condition_match', 'actions', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'stops_processing' => 'boolean',
            'conditions' => 'array',
            'actions' => 'array',
            'last_matched_at' => 'datetime',
        ];
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function runs()
    {
        return $this->hasMany(AutomationRun::class);
    }

    /** Enabled rules for one trigger, in the order they must run. */
    public function scopeForTrigger($query, string $trigger)
    {
        return $query->where('trigger', $trigger)
            ->where('is_enabled', true)
            ->orderBy('priority')
            ->orderBy('id');
    }
}
