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
    public const VARIABLES = ['name', 'agent', 'company', 'project', 'questions', 'link'];

    /**
     * What a message is *for*, so a journey can ask for "the welcome message"
     * and get the one written for this lead's project, language and stage
     * rather than naming one template for every project in the workspace.
     */
    public const PURPOSES = ['welcome', 'qualification', 'reminder', 'nurture', 'appointment', 'brochure', 'won', 'lost', 'interested', 'interested_follow_up', 'visit_invite', 'final'];

    /** Who a message is written for, read off what the lead told us. */
    public const AUDIENCES = ['investment', 'end_user'];

    /** The brochure, as a link — the only way a file can open a conversation. */
    public const BROCHURE_DEFAULT = [
        'name' => 'بروشور المشروع',
        'purpose' => 'brochure',
        'body' => 'مرحبًا {{name}}، هذا بروشور {{project}}: {{link}}',
    ];

    /** A gentle message for somebody who has gone quiet, or gone cold. */
    public const NURTURE_DEFAULT = [
        'name' => 'متابعة لطيفة',
        'purpose' => 'nurture',
        'body' => 'مرحبًا {{name}}، ما زلنا هنا إن احتجت أي تفاصيل عن {{project}}. يسعدنا مساعدتك متى ما ناسبك.',
    ];

    /** Asks the project's own questions, numbered, in one message. */
    public const QUALIFICATION_DEFAULT = [
        'name' => 'أسئلة التأهيل',
        'purpose' => 'qualification',
        'body' => "حتى نخدمك بشكل أدق يا {{name}}، نحتاج إجابتك على هذه الأسئلة:\n{{questions}}\n\nيكفي أن ترد برقم السؤال وإجابتك.",
    ];

    /**
     * What an interested customer is written, in four different messages — one at each moment of the
     * interested journey, so nobody is ever sent the same words twice. Short, personal, and each ends in
     * one thing to do. A workspace edits them or writes its own per project; these are only the start.
     */
    public const INTERESTED_DEFAULTS = [
        'whatsapp' => [
            ['name' => 'اهتمام: تعريف شخصي', 'purpose' => 'interested', 'body' => 'مرحبًا {{name}}، سعدنا باهتمامك بمشروع {{project}}. جهّزت لك ما يناسبك من التفاصيل، وأحب أن أسمع منك: ما أهم ما تبحث عنه؟ أستطيع الاتصال بك في الوقت الأنسب لك. {{agent}}'],
            ['name' => 'اهتمام: متابعة اليوم التالي', 'purpose' => 'interested_follow_up', 'body' => '{{name}}، هل اطلعت على تفاصيل {{project}}؟ إن أحببت أرتّب لك مكالمة قصيرة أجيب فيها عن كل ما يهمك.'],
            ['name' => 'اهتمام: دعوة لزيارة', 'purpose' => 'visit_invite', 'body' => '{{name}}، أفضل طريقة لتتعرف على {{project}} أن تراه بنفسك. متى تناسبك زيارة قصيرة هذا الأسبوع؟ أرتّب لك كل شيء.'],
            ['name' => 'اهتمام: كلمة أخيرة', 'purpose' => 'final', 'body' => '{{name}}، لن أطيل عليك. أبقيت لك باب {{project}} مفتوحًا — متى أحببت التفاصيل أو الزيارة يكفي أن تكتب لي هنا.'],
        ],
        'email' => [
            ['name' => 'بريد الاهتمام: تعريف شخصي', 'purpose' => 'interested', 'subject' => '{{name}}، ما يناسبك في {{project}}', 'body' => "مرحبًا {{name}}،\n\nسعدنا باهتمامك بمشروع {{project}}. جهّزت لك ما يناسبك من التفاصيل، وأحب أن أسمع منك: ما أهم ما تبحث عنه؟\n\nيمكنني الاتصال بك في الوقت الأنسب لك.\n\n{{agent}}"],
            ['name' => 'بريد الاهتمام: متابعة اليوم التالي', 'purpose' => 'interested_follow_up', 'subject' => 'هل وصلتك تفاصيل {{project}}؟', 'body' => "{{name}}،\n\nهل اطلعت على تفاصيل {{project}}؟ إن أحببت أرتّب لك مكالمة قصيرة أجيب فيها عن كل ما يهمك.\n\n{{agent}}"],
            ['name' => 'بريد الاهتمام: دعوة لزيارة', 'purpose' => 'visit_invite', 'subject' => 'زيارة قصيرة إلى {{project}}', 'body' => "{{name}}،\n\nأفضل طريقة لتتعرف على {{project}} أن تراه بنفسك. متى تناسبك زيارة قصيرة هذا الأسبوع؟ أرتّب لك كل شيء.\n\n{{agent}}"],
            ['name' => 'بريد الاهتمام: كلمة أخيرة', 'purpose' => 'final', 'subject' => 'بابنا مفتوح — {{project}}', 'body' => "{{name}}،\n\nلن أطيل عليك. أبقيت لك باب {{project}} مفتوحًا — متى أحببت التفاصيل أو الزيارة يكفي أن تردّ على هذه الرسالة.\n\n{{agent}}"],
        ],
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
