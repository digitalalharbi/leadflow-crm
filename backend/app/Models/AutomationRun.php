<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One rule's encounter with one event. The trace on a lead is these, in order.
 */
class AutomationRun extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = [
        'lead_id', 'automation_rule_id', 'trigger',
        'outcome', 'reason', 'applied_actions', 'correlation_id', 'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'applied_actions' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function rule()
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id')->withTrashed();
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }
}
