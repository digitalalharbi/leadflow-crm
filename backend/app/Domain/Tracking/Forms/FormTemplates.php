<?php

namespace App\Domain\Tracking\Forms;

/**
 * Forms to start from. Each is a whole form — fields, layout, design, settings — that a manager changes rather than builds: the
 * questions a real estate team actually asks, laid out for a phone, mapped onto the lead.
 */
final class FormTemplates
{
    /** @return array<string,array{name:string, description:string, definition:array<string,mixed>}> */
    public static function all(): array
    {
        return [
            'real_estate_interest' => ['name' => 'اهتمام بمشروع', 'description' => 'الاسم والجوال والميزانية ونوع العقار — الأسرع لجمع العملاء المهتمين.', 'definition' => self::interest()],
            'property_visit' => ['name' => 'طلب زيارة موقع', 'description' => 'موعد الزيارة المفضّل وعدد الزوّار.', 'definition' => self::visit()],
            'reservation_interest' => ['name' => 'الاهتمام بالحجز', 'description' => 'لمن وصل إلى مرحلة اختيار الوحدة: الوحدة، الدفعة، وطريقة الدفع.', 'definition' => self::reservation()],
            'investment_qualification' => ['name' => 'تأهيل مستثمر', 'description' => 'أولويات المستثمر وميزانيته وأفقه، مع تقييم تلقائي لجدّية الاهتمام.', 'definition' => self::investment()],
            'broker_lead' => ['name' => 'عميل من وسيط', 'description' => 'يسجّله الوسيط عن عميله: بيانات الوسيط وبيانات العميل.', 'definition' => self::broker()],
            'general_contact' => ['name' => 'تواصل عام', 'description' => 'اسم وجوال ورسالة. للاستفسارات التي لا تخص مشروعًا بعينه.', 'definition' => self::contact()],
        ];
    }

    /** @return array<string,mixed> */
    public static function get(string $key): ?array
    {
        return self::all()[$key]['definition'] ?? null;
    }

    private static function f(string $key, string $type, string $label, string $mapping = 'none', array $extra = []): array
    {
        return $extra + ['key' => $key, 'type' => $type, 'label' => $label, 'mapping' => $mapping, 'required' => false, 'hidden' => false];
    }

    /** @param  list<list<list<string>>>  $rows  a row is a list of columns, a column a list of keys */
    private static function section(?string $title, array $rows, ?string $stepTitle = null): array
    {
        return ['title' => $title, 'step_title' => $stepTitle, 'rows' => array_map(fn ($cols) => ['columns' => array_map(fn ($keys) => ['fields' => $keys], $cols)], $rows)];
    }

    private static function hidden(): array
    {
        return [
            self::f('project', 'hidden', 'المشروع', 'tracking.project', ['hidden' => true, 'default' => '@project']),
            self::f('unit', 'hidden', 'الوحدة', 'tracking.unit', ['hidden' => true, 'default' => '@unit']),
            self::f('source', 'hidden', 'المصدر', 'tracking.source', ['hidden' => true, 'default' => '@source']),
        ];
    }

    private static function wrap(string $headline, string $description, string $cta, array $fields, array $sections, array $settings = [], array $design = []): array
    {
        return [
            'headline' => $headline, 'description' => $description, 'cta_label' => $cta, 'consent_required' => true, 'benefits' => [],
            'thank_you_title' => 'شكرًا لاهتمامك', 'thank_you_message' => 'وصلتنا بياناتك، وسيتواصل معك فريقنا قريبًا.',
            'fields' => $fields, 'layout' => ['sections' => $sections], 'design' => $design + FormDesign::defaults(), 'settings' => $settings + ['mode' => 'single', 'default_country' => 'SA'],
        ];
    }

    private static function interest(): array
    {
        $fields = [
            self::f('name', 'text', 'الاسم', 'lead.full_name', ['required' => true]),
            self::f('mobile', 'phone', 'رقم الجوال', 'lead.phone', ['required' => true]),
            self::f('email', 'email', 'البريد الإلكتروني', 'lead.email'),
            self::f('city', 'text', 'المدينة', 'lead.city'),
            self::f('property_type', 'select', 'نوع العقار', 'lead.preferred_property_type', ['options' => [['value' => 'villa', 'label' => 'فيلا'], ['value' => 'apartment', 'label' => 'شقة'], ['value' => 'townhouse', 'label' => 'تاون هاوس'], ['value' => 'land', 'label' => 'أرض']]]),
            self::f('budget', 'number', 'الميزانية التقريبية (ريال)', 'lead.budget_max', ['validation' => ['min' => 0]]),
            self::f('purpose', 'radio', 'الغرض من الشراء', 'lead.purchase_purpose', ['options' => [['value' => 'residence', 'label' => 'سكن'], ['value' => 'investment', 'label' => 'استثمار']]]),
            self::f('timeline', 'select', 'متى تنوي الشراء؟', 'lead.purchase_timeline', ['options' => [['value' => 'now', 'label' => 'خلال شهر'], ['value' => 'quarter', 'label' => 'خلال 3 أشهر'], ['value' => 'year', 'label' => 'خلال سنة'], ['value' => 'exploring', 'label' => 'أستكشف فقط']]]),
            ...self::hidden(),
        ];

        return self::wrap('سجّل اهتمامك', 'اترك بياناتك وسنتواصل معك بالتفاصيل والأسعار.', 'اطلب التفاصيل', $fields, [
            self::section(null, [[['name'], ['mobile']], [['email'], ['city']], [['property_type'], ['budget']], [['purpose'], ['timeline']]]),
        ]);
    }

    private static function visit(): array
    {
        $fields = [
            self::f('intro', 'heading', 'احجز موعد زيارتك للموقع'),
            self::f('name', 'text', 'الاسم', 'lead.full_name', ['required' => true]),
            self::f('mobile', 'phone', 'رقم الجوال', 'lead.phone', ['required' => true]),
            self::f('visit_date', 'date', 'اليوم المفضّل للزيارة', 'custom.preferred_visit_date', ['required' => true]),
            self::f('visit_time', 'select', 'الوقت المفضّل', 'custom.preferred_visit_time', ['options' => [['value' => 'morning', 'label' => 'صباحًا'], ['value' => 'afternoon', 'label' => 'عصرًا'], ['value' => 'evening', 'label' => 'مساءً']]]),
            self::f('visitors', 'number', 'عدد الزوّار', 'custom.visitors_count', ['validation' => ['min' => 1, 'max' => 20]]),
            self::f('note', 'textarea', 'ملاحظات', 'custom.visit_note'),
            ...self::hidden(),
        ];

        return self::wrap('احجز زيارتك', 'نرتّب لك جولة في الموقع في الوقت المناسب لك.', 'احجز الزيارة', $fields, [
            self::section(null, [[['intro']], [['name'], ['mobile']], [['visit_date'], ['visit_time']], [['visitors']], [['note']]]),
        ], ['thanks' => ['actions' => [], 'redirect_url' => null, 'redirect_delay' => 5], 'gtm' => ['events' => true]]);
    }

    private static function reservation(): array
    {
        $fields = [
            self::f('name', 'text', 'الاسم', 'lead.full_name', ['required' => true]),
            self::f('mobile', 'phone', 'رقم الجوال', 'lead.phone', ['required' => true]),
            self::f('payment', 'radio', 'طريقة الدفع', 'lead.payment_method', ['required' => true, 'options' => [['value' => 'cash', 'label' => 'كاش'], ['value' => 'mortgage', 'label' => 'تمويل عقاري'], ['value' => 'undecided', 'label' => 'لم أقرر']]]),
            self::f('bank', 'text', 'الجهة التمويلية', 'custom.financing_bank', ['show_when' => ['match' => 'all', 'conditions' => [['field' => 'payment', 'op' => 'equals', 'value' => 'mortgage']]]]),
            self::f('timeline', 'select', 'متى تنوي الحجز؟', 'lead.purchase_timeline', ['options' => [['value' => 'now', 'label' => 'الآن'], ['value' => 'week', 'label' => 'خلال أسبوع'], ['value' => 'month', 'label' => 'خلال شهر']]]),
            self::f('intent', 'checkbox', 'أرغب بحجز وحدة', 'lead.reservation_intent'),
            ...self::hidden(),
        ];

        return self::wrap('احجز وحدتك', 'ثبّت وحدتك قبل نفاد الكمية.', 'ابدأ الحجز', $fields, [
            self::section(null, [[['name'], ['mobile']], [['payment']], [['bank']], [['timeline']], [['intent']]]),
        ], [
            'signals' => [
                ['id' => 'ready_to_reserve', 'label' => 'جاهز للحجز خلال أسبوع', 'points' => 15, 'when' => ['match' => 'all', 'conditions' => [['field' => 'timeline', 'op' => 'not_equals', 'value' => 'month'], ['field' => 'timeline', 'op' => 'not_empty']]]],
                ['id' => 'cash_or_financed', 'label' => 'دفع كاش', 'points' => 8, 'when' => ['match' => 'all', 'conditions' => [['field' => 'payment', 'op' => 'equals', 'value' => 'cash']]]],
            ],
        ]);
    }

    private static function investment(): array
    {
        $priorities = [
            'rows' => [['value' => 'return', 'label' => 'العائد الإيجاري'], ['value' => 'appreciation', 'label' => 'ارتفاع قيمة العقار'], ['value' => 'location', 'label' => 'الموقع'], ['value' => 'developer', 'label' => 'سمعة المطوّر']],
            'columns' => [['value' => 'low', 'label' => 'غير مهم'], ['value' => 'mid', 'label' => 'مهم'], ['value' => 'high', 'label' => 'مهم جدًا']],
            'multiple' => false, 'require_all_rows' => false, 'min_answers' => 0,
        ];
        $fields = [
            self::f('name', 'text', 'الاسم', 'lead.full_name', ['required' => true]),
            self::f('mobile', 'phone', 'رقم الجوال', 'lead.phone', ['required' => true]),
            self::f('budget', 'number', 'حجم الاستثمار (ريال)', 'lead.budget_max', ['required' => true, 'validation' => ['min' => 0]]),
            self::f('financing', 'select', 'طريقة التمويل', 'lead.payment_method', ['options' => [['value' => 'cash', 'label' => 'كاش'], ['value' => 'mortgage', 'label' => 'تمويل عقاري']]]),
            self::f('timeline', 'select', 'متى تنوي الاستثمار؟', 'lead.purchase_timeline', ['options' => [['value' => 'now', 'label' => 'خلال شهر'], ['value' => 'quarter', 'label' => 'خلال 3 أشهر'], ['value' => 'year', 'label' => 'خلال سنة']]]),
            self::f('horizon', 'select', 'أفقك الاستثماري', 'custom.investment_horizon', ['options' => [['value' => 'short', 'label' => 'أقل من 3 سنوات'], ['value' => 'mid', 'label' => '3 إلى 7 سنوات'], ['value' => 'long', 'label' => 'أكثر من 7 سنوات']], 'require_when' => ['match' => 'all', 'conditions' => [['field' => 'budget', 'op' => 'gte', 'value' => 1000000]]]]),
            self::f('priorities', 'matrix', 'ما أهمية العوامل التالية لك؟', 'custom.investor_priorities', ['matrix' => $priorities]),
            ...self::hidden(),
        ];

        return self::wrap('فرصة استثمارية', 'أخبرنا عن أهدافك لنقترح عليك الأنسب.', 'اطلب عرضًا استثماريًا', $fields, [
            self::section('بياناتك', [[['name'], ['mobile']]], 'بياناتك'),
            self::section('استثمارك', [[['budget'], ['financing']], [['timeline'], ['horizon']]], 'استثمارك'),
            self::section('أولوياتك', [[['priorities']]], 'أولوياتك'),
        ], [
            'mode' => 'multi',
            'signals' => [
                ['id' => 'budget_high', 'label' => 'ميزانية 2 مليون فأكثر', 'points' => 12, 'when' => ['match' => 'all', 'conditions' => [['field' => 'budget', 'op' => 'gte', 'value' => 2000000]]]],
                ['id' => 'soon', 'label' => 'جاهز للاستثمار خلال 3 أشهر', 'points' => 10, 'when' => ['match' => 'any', 'conditions' => [['field' => 'timeline', 'op' => 'equals', 'value' => 'now'], ['field' => 'timeline', 'op' => 'equals', 'value' => 'quarter']]]],
                ['id' => 'financing_ready', 'label' => 'جاهزية التمويل', 'points' => 8, 'when' => ['match' => 'all', 'conditions' => [['field' => 'financing', 'op' => 'equals', 'value' => 'cash']]]],
            ],
        ]);
    }

    private static function broker(): array
    {
        $fields = [
            self::f('broker_name', 'text', 'اسم الوسيط', 'custom.broker_name', ['required' => true]),
            self::f('broker_mobile', 'phone', 'جوال الوسيط', 'custom.broker_mobile', ['required' => true]),
            self::f('name', 'text', 'اسم العميل', 'lead.full_name', ['required' => true]),
            self::f('mobile', 'phone', 'جوال العميل', 'lead.phone', ['required' => true]),
            self::f('budget', 'number', 'ميزانية العميل', 'lead.budget_max'),
            self::f('note', 'textarea', 'ملاحظات للمبيعات', 'custom.broker_note'),
            ...self::hidden(),
        ];

        return self::wrap('سجّل عميلك', 'نتابع عميلك ونُبقيك على اطلاع.', 'أرسل العميل', $fields, [
            self::section('بيانات الوسيط', [[['broker_name'], ['broker_mobile']]]),
            self::section('بيانات العميل', [[['name'], ['mobile']], [['budget']], [['note']]]),
        ]);
    }

    private static function contact(): array
    {
        $fields = [
            self::f('name', 'text', 'الاسم', 'lead.full_name', ['required' => true]),
            self::f('mobile', 'phone', 'رقم الجوال', 'lead.phone', ['required' => true]),
            self::f('email', 'email', 'البريد الإلكتروني', 'lead.email'),
            self::f('message', 'textarea', 'رسالتك', 'custom.message', ['validation' => ['max_length' => 600]]),
            ...self::hidden(),
        ];

        return self::wrap('تواصل معنا', 'اكتب لنا وسنردّ عليك.', 'أرسل', $fields, [
            self::section(null, [[['name'], ['mobile']], [['email']], [['message']]]),
        ]);
    }
}
