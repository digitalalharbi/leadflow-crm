<?php

namespace App\Models;

use App\Domain\Automation\Thresholds;
use App\Models\Concerns\TenantOwnedSingleton;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantSetting extends Model
{
    use HasFactory, TenantOwnedSingleton;

    protected static function booted(): void
    {
        static::saved(fn () => Thresholds::flush());

        /*
         * Whose settings `TenantSetting::query()->first()` means. The model has no global scope on
         * purpose (the login screen reads it before anyone is signed in, when no tenant is bound), so
         * code that asks for "the workspace's settings" with a tenant bound used to get whichever
         * workspace's row came first — its thresholds, its quiet hours, its AI key. With a tenant
         * bound the question now has one answer; with none bound nothing changes.
         */
        static::addGlobalScope('bound-tenant', function ($query) {
            $id = app(TenantContext::class)->id();

            if ($id !== null) {
                $query->where($query->getModel()->getTable().'.tenant_id', $id);
            }
        });
    }

    protected $fillable = [
        'sla_rules',
        'locale', 'currency', 'timezone', 'default_country_code',
        'assignment_mode', 'round_robin_cursor',
        'assignment_strategy',
        'score_warm_threshold', 'score_hot_threshold', 'first_contact_target_minutes',
        'sla_remind_after_minutes', 'sla_escalate_after_minutes', 'sla_reassign_after_minutes',
        'whatsapp_template', 'onboarding', 'require_mfa',
        'work_days', 'work_starts_at', 'work_ends_at',
        'score_priority_threshold', 'high_value_from', 'stalled_after_days',
        'sla_hot_minutes', 'sla_interested_minutes', 'sla_visit_minutes', 'sla_reservation_minutes',
        'quiet_hours_enabled', 'quiet_hours_start', 'quiet_hours_end', 'max_contacts_per_day', 'contact_cooldown_minutes',
        'ai_api_key', 'ai_verified_at', 'ai_last_error',
    ];

    /** The AI key is a secret: encrypted at rest, never serialised. */
    protected $hidden = ['ai_api_key'];

    protected function casts(): array
    {
        return [
            'work_days' => 'array',
            'sla_rules' => 'array',
            'require_mfa' => 'boolean',
            'anomaly_settings' => 'array',
            'self_approval' => 'array',
            'score_priority_threshold' => 'integer',
            'high_value_from' => 'integer',
            'stalled_after_days' => 'integer',
            'sla_hot_minutes' => 'integer',
            'sla_interested_minutes' => 'integer',
            'sla_visit_minutes' => 'integer',
            'sla_reservation_minutes' => 'integer',
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
