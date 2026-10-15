<?php

namespace App\Models;

use App\Enums\MessageChannel;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant's own saved messages — not Meta's approved template catalogue.
 * These are the three or four sentences a rep sends twenty times a day.
 */
class MessageTemplate extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    /** Placeholders a template may use. Anything else is left alone. */
    public const VARIABLES = ['name', 'agent', 'company', 'project'];

    public const DEFAULTS = [
        [
            'name' => 'التعريف الأول',
            'body' => 'مرحبًا {{name}}، معك {{agent}} من {{company}} بخصوص اهتمامك بمشروع {{project}}. هل أقدر أساعدك بأي استفسار؟',
        ],
        [
            'name' => 'متابعة بدون رد',
            'body' => 'مرحبًا {{name}}، حاولت التواصل معك بخصوص {{project}}. متى يناسبك أتواصل معك؟',
        ],
        [
            'name' => 'تأكيد الموعد',
            'body' => 'مرحبًا {{name}}، نؤكد موعدك لزيارة {{project}}. بانتظارك، وأي تعديل على الموعد أبلغني.',
        ],
        [
            'name' => 'تفاصيل الأسعار',
            'body' => 'مرحبًا {{name}}، هذه تفاصيل الوحدات المتاحة في {{project}}. أخبرني أي نوع يناسبك وأرسل لك التفاصيل كاملة.',
        ],
    ];

    protected $fillable = [
        'name', 'body', 'channel', 'is_active', 'sort_order',
        'meta_template_name', 'meta_language', 'meta_parameters',
    ];

    protected function casts(): array
    {
        return [
            'channel' => MessageChannel::class,
            'is_active' => 'boolean',
            'meta_parameters' => 'array',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
