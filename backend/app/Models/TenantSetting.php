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
        'quiet_hours_enabled', 'quiet_hours_start', 'quiet_hours_end', 'max_contacts_per_day', 'contact_cooldown_minutes',
        'ai_api_key', 'ai_verified_at', 'ai_last_error',
    ];

    /** The AI key is a secret: encrypted at rest, never serialised. */
    protected $hidden = ['ai_api_key'];

    protected function casts(): array
    {
        return [
            'work_days' => 'array',
            'quiet_hours_enabled' => 'boolean',
            'max_contacts_per_day' => 'integer',
            'contact_cooldown_minutes' => 'integer',
            'round_robin_cursor' => 'integer',
            'score_warm_threshold' => 'integer',
            'score_hot_threshold' => 'integer',
            'ai_api_key' => 'encrypted',
            'ai_verified_at' => 'datetime',
        ];
    }
}
