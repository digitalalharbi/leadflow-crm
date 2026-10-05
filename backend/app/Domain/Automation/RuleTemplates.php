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
            'name' => 'عميل ساخن: أولوية قصوى',
            'description' => 'العميل مرتفع النية يُرفع تقييمه ويُنبَّه المندوب فورًا.',
            'trigger' => 'lead.created',
            'priority' => 5,
            'conditions' => [
                ['field' => 'temperature', 'operator' => 'equals', 'value' => 'hot'],
            ],
            'actions' => [
                ['type' => 'adjust_score', 'by' => 15],
                ['type' => 'add_tag', 'tag' => 'أولوية'],
                ['type' => 'notify', 'audience' => 'owner', 'message' => 'عميل ساخن — اتصل الآن.'],
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
