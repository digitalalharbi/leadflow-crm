<?php

namespace App\Models;

use App\Enums\MessageChannel;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tenant's own saved messages — not Meta's approved template catalogue.
 * These are the three or four sentences a rep sends twenty times a day.
 */
class MessageTemplate extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    /** Placeholders a template may use. Anything else is left alone. */
    public const VARIABLES = ['name', 'agent', 'company', 'project', 'questions'];

    /**
     * What a message is *for*, so a journey can ask for "the welcome message"
     * and get the one written for this lead's project, language and stage
     * rather than naming one template for every project in the workspace.
     */
    public const PURPOSES = ['welcome', 'qualification', 'reminder', 'nurture', 'appointment', 'brochure', 'won', 'lost'];

    /** Who a message is written for, read off what the lead told us. */
    public const AUDIENCES = ['investment', 'end_user'];

    /** Asks the project's own questions, numbered, in one message. */
    public const QUALIFICATION_DEFAULT = [
        'name' => 'أسئلة التأهيل',
        'purpose' => 'qualification',
        'body' => "حتى نخدمك بشكل أدق يا {{name}}، نحتاج إجابتك على هذه الأسئلة:\n{{questions}}\n\nيكفي أن ترد برقم السؤال وإجابتك.",
    ];

    public const DEFAULTS = [
        [
            'name' => 'التعريف الأول',
            'purpose' => 'welcome',
            'body' => 'مرحبًا {{name}}، معك {{agent}} من {{company}} بخصوص اهتمامك بمشروع {{project}}. هل أقدر أساعدك بأي استفسار؟',
        ],
        [
            'name' => 'متابعة بدون رد',
            'purpose' => 'reminder',
            'body' => 'مرحبًا {{name}}، حاولت التواصل معك بخصوص {{project}}. متى يناسبك أتواصل معك؟',
        ],
        [
            'name' => 'تأكيد الموعد',
            'purpose' => 'appointment',
            'body' => 'مرحبًا {{name}}، نؤكد موعدك لزيارة {{project}}. بانتظارك، وأي تعديل على الموعد أبلغني.',
        ],
        [
            'name' => 'تفاصيل الأسعار',
            'body' => 'مرحبًا {{name}}، هذه تفاصيل الوحدات المتاحة في {{project}}. أخبرني أي نوع يناسبك وأرسل لك التفاصيل كاملة.',
        ],
    ];

    /** The email set: a subject as well as a body, and room to say more. */
    public const EMAIL_DEFAULTS = [
        [
            'name' => 'ترحيب وتعريف',
            'purpose' => 'welcome',
            'subject' => 'شكرًا لاهتمامك بمشروع {{project}}',
            'body' => "مرحبًا {{name}}،\n\nمعك {{agent}} من {{company}}. شكرًا لاهتمامك بمشروع {{project}}.\n\nيسعدني أن أجيب عن أي سؤال، وأن أرتب لك زيارة أو اتصالًا في الوقت الذي يناسبك.\n\nتحياتي،\n{{agent}}",
        ],
        [
            'name' => 'متابعة بعد عدم الرد',
            'purpose' => 'nurture',
            'subject' => 'بخصوص {{project}}',
            'body' => "مرحبًا {{name}}،\n\nحاولت التواصل معك بخصوص {{project}}. متى يناسبك أن أتصل بك؟\n\nتحياتي،\n{{agent}}",
        ],
    ];

    protected $fillable = [
        'name', 'subject', 'body', 'channel', 'is_active', 'sort_order',
        'meta_template_name', 'meta_language', 'meta_parameters',
        'project_id', 'purpose', 'language', 'stage', 'audience',
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

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
