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
            'description' => 'ترحيب واتساب وبريد فورًا، ثم البروشور المناسب، ثم أسئلة التأهيل، وتذكير إن لم يرد، ثم تسليم لمندوب.',
            'trigger' => 'lead.created',
            'priority' => 6,
            'conditions' => [],
            'actions' => [
                ['type' => 'send_whatsapp', 'purpose' => 'welcome'],
                ['type' => 'send_email', 'purpose' => 'welcome'],
                ['type' => 'share_file', 'kind' => '', 'send' => true, 'channel' => 'whatsapp'],
                ['type' => 'hand_off', 'reason' => 'unreached', 'when' => 'unreached'],
                ['type' => 'ask_qualification', 'delay_minutes' => 3, 'unless' => 'replied'],
                ['type' => 'send_whatsapp', 'purpose' => 'reminder', 'delay_minutes' => 180, 'unless' => 'replied'],
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
