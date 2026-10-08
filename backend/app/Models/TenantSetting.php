<?php

namespace App\Models;

use App\Models\Concerns\TenantOwnedSingleton;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantSetting extends Model
{
    use HasFactory, TenantOwnedSingleton;

    protected $fillable = [
        'locale', 'currency', 'timezone', 'default_country_code',
        'assignment_mode', 'round_robin_cursor',
        'assignment_strategy',
        'score_warm_threshold', 'score_hot_threshold', 'first_contact_target_minutes',
        'sla_remind_after_minutes', 'sla_escalate_after_minutes', 'sla_reassign_after_minutes',
        'whatsapp_template', 'onboarding',
        'work_days', 'work_starts_at', 'work_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'work_days' => 'array',
            'round_robin_cursor' => 'integer',
            'score_warm_threshold' => 'integer',
            'score_hot_threshold' => 'integer',
        ];
    }
}
