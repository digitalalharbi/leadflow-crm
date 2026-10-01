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
        'score_warm_threshold', 'score_hot_threshold', 'first_contact_target_minutes',
        'whatsapp_template', 'onboarding',
    ];

    protected function casts(): array
    {
        return [
            'onboarding' => 'array',
            'round_robin_cursor' => 'integer',
            'score_warm_threshold' => 'integer',
            'score_hot_threshold' => 'integer',
        ];
    }
}
