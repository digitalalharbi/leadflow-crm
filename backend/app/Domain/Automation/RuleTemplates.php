<?php

namespace App\Domain\Automation;

/**
 * The rules nearly every sales floor writes, written once.
 *
 * A rules builder is only as useful as the first rule somebody manages to
 * finish in it. Faced with an empty trigger/condition/action form, most
 * workspaces write nothing and keep doing the work by hand — which is the
 * outcome the whole product exists to prevent. These six are the automations a
 * real estate sales team describes when you ask what they wish happened on its
 * own, phrased so a manager can read one and recognise their own process.
 *
 * Each installs as an ordinary rule: editable, disableable, visible in the
 * trace. Nothing here is a special case the engine knows about — a template is
 * a starting point, not a mode.
 */
class RuleTemplates
{
    /**
     * Priorities are spaced so a workspace can slot its own rules between the
     * installed ones without renumbering anything.
     */
    private const TEMPLATES = [
        /*
         * The journey. Every lead starts here the moment it arrives, and no
         * lead waits for a person to start the conversation:
         *
         *   welcome on WhatsApp and by email → the right brochure → the
         *   project's own questions → a reminder if they are quiet → a gentler
         *   email the next day → and only then, still quiet, a person's turn.
         *
         * Messages are named by purpose, so each lead gets the version written
         * for its project, language and stage. Every waiting step carries
         * `unless: replied` — a customer who answered is a conversation, not a
         * sequence, and is handed to a person by `reply_hand_off` instead.
         * A lead nothing could reach (no WhatsApp connected, no email) is
         * handed over at once rather than left in a journey that cannot run.
         */
        'lead_journey' => [
            'name' => 'رحلة العميل: تواصل آلي من أول دقيقة',
            'description' => 'ترحيب واتساب وبريد فورًا، ثم البروشور، ثم أسئلة التأهيل، وتذكير بعد 15 دقيقة إن لم يرد، ثم تسليم لمندوب.',
            'trigger' => 'lead.created',
            'priority' => 6,
            'conditions' => [],
            'actions' => [
                ['type' => 'send_whatsapp', 'purpose' => 'welcome'],
                ['type' => 'send_email', 'purpose' => 'welcome'],
                ['type' => 'share_file', 'kind' => '', 'send' => true, 'channel' => 'whatsapp'],
                ['type' => 'hand_off', 'reason' => 'unreached', 'when' => 'unreached'],
                ['type' => 'ask_qualification', 'delay_minutes' => 3, 'unless' => 'replied'],
                ['type' => 'send_whatsapp', 'purpose' => 'reminder', 'delay_minutes' => 15, 'unless' => 'replied'],
                ['type' => 'send_email', 'purpose' => 'nurture', 'delay_minutes' => 1440, 'unless' => 'replied'],
                ['type' => 'hand_off', 'reason' => 'no_reply', 'delay_minutes' => 2880, 'unless' => 'replied'],
            ],
        ],

        // The moment a customer answers, it is a person's conversation.
        'reply_hand_off' => [
            'name' => 'العميل ردّ: رفع الأولوية وتسليم للمندوب',
            'description' => 'عند أول رد يُرفع التقييم، ويصبح أولوية إن كان اهتمامه مرتفعًا، ويُسلَّم للمندوب ليكمل نفس المحادثة.',
            'trigger' => 'lead.replied',
            'priority' => 5,
            'conditions' => [],
            'actions' => [
                ['type' => 'adjust_score', 'by' => 10],
                ['type' => 'set_priority', 'when' => 'hot'],
                ['type' => 'hand_off', 'reason' => 'replied'],
            ],
        ],

        'qualified_hand_off' => [
            'name' => 'عميل مؤهَّل: أولوية وتسليم فوري',
            'description' => 'العميل الذي اجتاز أسئلة التأهيل يصبح أولوية ويُسلَّم للمندوب للاتصال وحجز موعد.',
            'trigger' => 'lead.qualified',
            'priority' => 5,
            'conditions' => [],
            'actions' => [
                ['type' => 'set_priority'],
                ['type' => 'hand_off', 'reason' => 'qualified'],
            ],
        ],

        'visit_follow_up' => [
            'name' => 'بعد الزيارة: مهمة ونتيجة',
            'description' => 'عند إتمام الزيارة تُنشأ مهمة ليسجّل المندوب نتيجتها ويتابع العميل.',
            'trigger' => 'lead.visit_completed',
            'priority' => 40,
            'conditions' => [],
            'actions' => [['type' => 'create_task', 'title' => 'سجّل نتيجة الزيارة وتابع العميل', 'in_hours' => 4, 'priority' => 'high']],
        ],

        'reservation_expiry' => [
            'name' => 'حجز يوشك أن ينتهي',
            'description' => 'قبل انتهاء الحجز بيوم: مهمة للمندوب وتنبيه للإدارة.',
            'trigger' => 'lead.reservation_expiring',
            'priority' => 40,
            'conditions' => [],
            'actions' => [
                ['type' => 'create_task', 'title' => 'الحجز ينتهي خلال يوم: أكّد مع العميل', 'in_hours' => 2, 'priority' => 'high'],
                ['type' => 'notify', 'audience' => 'managers', 'message' => 'حجز ينتهي خلال يوم.'],
            ],
        ],

        'sla_escalation' => [
            'name' => 'تصعيد تجاوز المهلة',
            'description' => 'عند تجاوز مهلة أول تواصل: تنبيه للإدارة ومهمة عاجلة.',
            'trigger' => 'lead.sla_breached',
            'priority' => 20,
            'conditions' => [],
            'actions' => [['type' => 'escalate_sla']],
        ],

        'sheet_import_assign' => [
            'name' => 'صف جديد من Google Sheets',
            'description' => 'عند وصول عميل من جدول: إسناد وتنبيه ومتابعة.',
            'trigger' => 'lead.sheet_row',
            'priority' => 20,
            'conditions' => [],
            'actions' => [
                ['type' => 'assign', 'strategy' => 'round_robin'],
                ['type' => 'notify', 'audience' => 'owner', 'message' => 'عميل جديد من الجدول.'],
                ['type' => 'schedule_follow_up', 'in_hours' => 1, 'note' => 'أول اتصال'],
            ],
        ],

        'new_lead_assign_notify' => [
            'name' => 'عميل جديد: إسناد وتنبيه ومتابعة',
            'description' => 'يُسنَد العميل الجديد لأقل مندوب انشغالًا، ويُنبَّه فورًا، وتُحدَّد متابعة خلال ساعة.',
            'trigger' => 'lead.created',
            'priority' => 10,
            'conditions' => [],
            'actions' => [
                ['type' => 'assign', 'strategy' => 'round_robin'],
                ['type' => 'notify', 'audience' => 'owner', 'message' => 'عميل جديد بانتظار أول اتصال.'],
                ['type' => 'schedule_follow_up', 'in_hours' => 1, 'note' => 'أول اتصال'],
            ],
        ],

        // The product's promise as one rule: arrive → assigned → brochure sent →
        // owner told → wait → nudge if nothing came back → follow-up. Every
        // delayed step is re-checked when it runs, so a customer who replied in
        // the meantime is not nudged. The WhatsApp/email steps are not in here
        // because they need a template the workspace has chosen; the editor adds
        // them in two clicks, and nothing is ever sent from a default.
        'welcome_sequence' => [
            'name' => 'عميل جديد: بروشور ثم متابعة تلقائية',
            'description' => 'يُسنَد العميل، يصله الملف المناسب، يُنبَّه المندوب، وإن لم يرد خلال ٤ ساعات يُذكَّر المندوب وتُحدَّد متابعة.',
            'trigger' => 'lead.created',
            'priority' => 8,
            'conditions' => [],
            'actions' => [
                ['type' => 'assign', 'strategy' => 'round_robin'],
                ['type' => 'share_file', 'kind' => '', 'send' => true, 'channel' => 'whatsapp'],
                ['type' => 'notify', 'audience' => 'owner', 'message' => 'عميل جديد وصله الملف — تواصل معه.'],
                ['type' => 'notify', 'audience' => 'owner', 'message' => 'لم يرد العميل بعد — حان وقت الاتصال.', 'delay_minutes' => 240],
                ['type' => 'schedule_follow_up', 'in_hours' => 24, 'note' => 'متابعة بعد يوم دون رد', 'delay_minutes' => 1440],
            ],
        ],

        'no_reply_escalate' => [
            'name' => 'لا رد: تذكير ثم تصعيد',
            'description' => 'عميل لم يُتواصل معه رغم مرور ساعتين — تذكير للمندوب وتنبيه للإدارة.',
            'trigger' => 'lead.stage_changed',
            'priority' => 20,
            'conditions' => [
                ['field' => 'was_contacted', 'operator' => 'equals', 'value' => false],
                ['field' => 'minutes_since_created', 'operator' => 'greater_than', 'value' => 120],
            ],
            'actions' => [
                ['type' => 'notify', 'audience' => 'owner', 'message' => 'مضى وقت طويل دون تواصل مع هذا العميل.'],
                ['type' => 'notify', 'audience' => 'managers', 'message' => 'عميل تجاوز مهلة أول تواصل دون رد من المندوب.'],
            ],
        ],

        'hot_lead_priority' => [
            'name' => 'اهتمام مرتفع: أولوية قصوى',
            'description' => 'العميل مرتفع النية يُرفع تقييمه ويُنبَّه المندوب فورًا.',
            'trigger' => 'lead.created',
            'priority' => 5,
            'conditions' => [
                ['field' => 'temperature', 'operator' => 'equals', 'value' => 'hot'],
            ],
            'actions' => [
                ['type' => 'adjust_score', 'by' => 15],
                ['type' => 'add_tag', 'tag' => 'أولوية'],
                ['type' => 'notify', 'audience' => 'owner', 'message' => 'اهتمام مرتفع — اتصل الآن.'],
            ],
        ],

        'appointment_reminder' => [
            'name' => 'موعد: تذكير قبل اللقاء',
            'description' => 'عند حجز موعد، تُحدَّد متابعة قبله بوقت كافٍ لتأكيد الحضور.',
            'trigger' => 'lead.stage_changed',
            'priority' => 30,
            'conditions' => [
                ['field' => 'status', 'operator' => 'equals', 'value' => 'appointment'],
            ],
            'actions' => [
                ['type' => 'schedule_follow_up', 'in_hours' => 20, 'note' => 'تأكيد الموعد'],
                ['type' => 'notify', 'audience' => 'owner', 'message' => 'تذكير: أكّد الموعد مع العميل.'],
            ],
        ],

        'lost_stop_chasing' => [
            'name' => 'خسارة: إيقاف المتابعات',
            'description' => 'عند إغلاق العميل كخسارة تتوقف كل المتابعات المفتوحة.',
            'trigger' => 'lead.lost',
            'priority' => 40,
            'conditions' => [],
            'actions' => [
                ['type' => 'stop_follow_ups'],
                ['type' => 'add_note', 'body' => 'أُوقفت المتابعات تلقائيًا بعد إغلاق العميل.'],
            ],
        ],

        'won_close_and_credit' => [
            'name' => 'بيع ناجح: إغلاق وتوثيق المصدر',
            'description' => 'عند نجاح الصفقة تتوقف المتابعات، ويُنبَّه المدير، ويُسجَّل مصدر العميل للإسناد.',
            'trigger' => 'lead.won',
            'priority' => 40,
            'conditions' => [],
            'actions' => [
                ['type' => 'stop_follow_ups'],
                ['type' => 'add_tag', 'tag' => 'صفقة ناجحة'],
                ['type' => 'notify', 'audience' => 'managers', 'message' => 'صفقة ناجحة — راجع الحملة التي جاءت منها.'],
            ],
        ],

        // ---- The pack: one press each. -----------------------------------
        'new_lead_welcome' => [
            'name' => 'ترحيب بالعميل الجديد',
            'description' => 'عميل جديد ← واتساب + بريد + بروشور فورًا.',
            'trigger' => 'lead.created', 'priority' => 7, 'conditions' => [['field' => 'has_phone', 'operator' => 'equals', 'value' => true]],
            'actions' => [['type' => 'send_whatsapp', 'purpose' => 'welcome'], ['type' => 'send_email', 'purpose' => 'welcome'], ['type' => 'share_file', 'kind' => '', 'send' => true, 'channel' => 'whatsapp']],
        ],
        'instant_whatsapp' => [
            'name' => 'واتساب فوري', 'description' => 'عميل جديد ← رسالة ترحيب على واتساب.',
            'trigger' => 'lead.created', 'priority' => 7, 'conditions' => [],
            'actions' => [['type' => 'send_whatsapp', 'purpose' => 'welcome']],
        ],
        'instant_email' => [
            'name' => 'بريد فوري', 'description' => 'عميل جديد بريده معروف ← بريد ترحيب.',
            'trigger' => 'lead.created', 'priority' => 7, 'conditions' => [['field' => 'has_email', 'operator' => 'equals', 'value' => true]],
            'actions' => [['type' => 'send_email', 'purpose' => 'welcome']],
        ],
        'send_brochure' => [
            'name' => 'إرسال البروشور', 'description' => 'عميل جديد ← البروشور المناسب لمشروعه.',
            'trigger' => 'lead.created', 'priority' => 8, 'conditions' => [],
            'actions' => [['type' => 'share_file', 'kind' => '', 'send' => true, 'channel' => 'whatsapp']],
        ],
        'qualification_flow' => [
            'name' => 'مسار التأهيل', 'description' => 'بعد 3 دقائق ← أسئلة المشروع، إن لم يرد بعد.',
            'trigger' => 'lead.created', 'priority' => 9, 'conditions' => [],
            'actions' => [['type' => 'ask_qualification', 'delay_minutes' => 3, 'unless' => 'replied']],
        ],
        'no_reply_follow_up' => [
            'name' => 'لا رد 15 دقيقة', 'description' => 'لا رد 15 د ← تذكير واتساب.',
            'trigger' => 'lead.no_reply', 'priority' => 12, 'conditions' => [['field' => 'no_reply_minutes', 'operator' => 'equals', 'value' => 15]],
            'actions' => [['type' => 'send_whatsapp', 'purpose' => 'reminder']],
        ],
        'hot_lead_escalation' => [
            'name' => 'تصعيد العميل ذي الاهتمام المرتفع', 'description' => 'أصبح اهتمامه مرتفعًا ← أولوية + تسليم للمندوب.',
            'trigger' => 'lead.score_changed', 'priority' => 5, 'conditions' => [['field' => 'temperature', 'operator' => 'equals', 'value' => 'hot']],
            'actions' => [['type' => 'set_priority'], ['type' => 'hand_off', 'reason' => 'needs_human']],
        ],
        'priority_lead' => [
            'name' => 'تقييم ≥ 80: أولوية', 'description' => 'التقييم 80 فأكثر ← أولوية وتنبيه المندوب.',
            'trigger' => 'lead.score_changed', 'priority' => 5, 'conditions' => [['field' => 'score', 'operator' => 'greater_than', 'value' => 79]],
            'actions' => [['type' => 'set_priority'], ['type' => 'notify', 'audience' => 'owner', 'message' => 'عميل وصل تقييمه 80 — أولوية.']],
        ],
        'no_answer_retry' => [
            'name' => 'لم يرد على الاتصال: إعادة', 'description' => 'لم يرد ← واتساب تذكير + مهمة اتصال بعد ساعتين.',
            'trigger' => 'lead.outcome_recorded', 'priority' => 15, 'conditions' => [['field' => 'last_outcome', 'operator' => 'equals', 'value' => 'no_answer']],
            'actions' => [['type' => 'send_whatsapp', 'purpose' => 'reminder'], ['type' => 'create_call_task', 'in_hours' => 2, 'note' => 'إعادة الاتصال']],
        ],
        'cold_lead_nurture' => [
            'name' => 'رعاية العميل ذي الاهتمام المنخفض', 'description' => 'انخفض اهتمامه ← رسالة رعاية ويعود للأتمتة.',
            'trigger' => 'lead.score_changed', 'priority' => 25, 'conditions' => [['field' => 'temperature', 'operator' => 'equals', 'value' => 'cold']],
            'actions' => [['type' => 'start_nurture']],
        ],
        're_engagement' => [
            'name' => 'إعادة التنشيط', 'description' => 'صامت 7 أيام ← رسالة متابعة لطيفة.',
            'trigger' => 'lead.no_reply', 'priority' => 30, 'conditions' => [['field' => 'no_reply_minutes', 'operator' => 'equals', 'value' => 10080]],
            'actions' => [['type' => 'send_whatsapp', 'purpose' => 'nurture']],
        ],
        'invalid_lead' => [
            'name' => 'عميل غير صالح', 'description' => 'بلا جوال ولا بريد ← غير صالح وإيقاف الأتمتة.',
            'trigger' => 'lead.created', 'priority' => 1, 'condition_match' => 'all',
            'conditions' => [['field' => 'has_phone', 'operator' => 'equals', 'value' => false], ['field' => 'has_email', 'operator' => 'equals', 'value' => false]],
            'actions' => [['type' => 'change_stage', 'status' => 'invalid'], ['type' => 'stop_automation']],
        ],
        'duplicate_lead' => [
            'name' => 'عميل عاد من جديد', 'description' => 'رقم معروف وصل مرة أخرى ← تقييم +5 وتنبيه المندوب.',
            'trigger' => 'lead.re_engaged', 'priority' => 10, 'conditions' => [],
            'actions' => [['type' => 'adjust_score', 'by' => 5], ['type' => 'notify', 'audience' => 'owner', 'message' => 'عميل عاد وسجّل اهتمامه مرة أخرى.']],
        ],
        'sla_breach' => [
            'name' => 'تجاوز مهلة أول تواصل', 'description' => 'تجاوز المهلة ← تسليم ومهمة اتصال عاجلة.',
            'trigger' => 'lead.sla_breached', 'priority' => 5, 'conditions' => [],
            'actions' => [['type' => 'create_call_task', 'in_hours' => 1, 'note' => 'اتصال عاجل — تجاوز المهلة'], ['type' => 'notify', 'audience' => 'managers', 'message' => 'عميل تجاوز مهلة أول تواصل.']],
        ],
        'lost_lead_follow_up' => [
            'name' => 'متابعة العميل المفقود', 'description' => 'بعد 30 يومًا من الخسارة ← رسالة رعاية.',
            'trigger' => 'lead.lost', 'priority' => 45, 'conditions' => [],
            'actions' => [['type' => 'send_whatsapp', 'purpose' => 'nurture', 'delay_minutes' => 43200, 'unless' => 'replied']],
        ],
        'brochure_opened' => [
            'name' => 'فتح البروشور', 'description' => 'فتح البروشور ← تقييم +15.',
            'trigger' => 'lead.file_opened', 'priority' => 20, 'conditions' => [],
            'actions' => [['type' => 'adjust_score', 'by' => 15]],
        ],
    ];

    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        return array_map(
            fn (string $key) => ['key' => $key] + self::TEMPLATES[$key],
            array_keys(self::TEMPLATES),
        );
    }

    public static function has(string $key): bool
    {
        return isset(self::TEMPLATES[$key]);
    }

    /** @return array<string, mixed> */
    public static function get(string $key): array
    {
        return ['key' => $key] + self::TEMPLATES[$key];
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::TEMPLATES);
    }
}
