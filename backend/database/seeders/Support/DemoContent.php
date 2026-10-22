<?php

namespace Database\Seeders\Support;

/**
 * Shared Arabic content for the demo and E2E seeders.
 *
 * Kept in one place so a staging demo and a test fixture draw on the same
 * vocabulary — a screenshot from either looks like the same real product.
 */
final class DemoContent
{
    public const FIRST_NAMES = [
        'محمد', 'عبدالله', 'أحمد', 'فهد', 'سعود', 'خالد', 'نواف', 'بندر', 'ماجد', 'تركي',
        'عمر', 'سلطان', 'ياسر', 'راكان', 'مشعل', 'عبدالعزيز', 'مشاري', 'فيصل', 'وليد', 'زياد',
        'نورة', 'سارة', 'ريم', 'لمى', 'هند', 'منى', 'أسماء', 'دانة', 'شهد', 'الجوهرة',
        'العنود', 'لطيفة', 'مها', 'نوف', 'جواهر',
    ];

    public const LAST_NAMES = [
        'الحربي', 'القحطاني', 'الشمري', 'العتيبي', 'الدوسري', 'الغامدي', 'الزهراني',
        'المطيري', 'السبيعي', 'الشهري', 'البقمي', 'الرشيدي', 'العنزي', 'الجهني', 'المالكي',
        'الثقفي', 'البلوي', 'الخالدي', 'السهلي', 'العسيري',
    ];

    public const CITIES = [
        'الرياض', 'جدة', 'الدمام', 'الخبر', 'مكة المكرمة',
        'المدينة المنورة', 'الطائف', 'بريدة', 'أبها', 'الأحساء',
    ];

    public const PROPERTY_TYPES = ['شقة', 'فيلا', 'دوبلكس', 'أرض سكنية', 'تاون هاوس', 'شقة بروف'];

    public const TIMELINES = ['immediate', 'within_month', 'within_3_months', 'within_6_months', 'exploring'];

    /**
     * Campaigns as a media buyer would actually name them, spread deliberately
     * across every platform the product supports.
     *
     * [campaign, platform, ad set, ad creative, source]
     */
    /**
     * Campaigns with deliberately different shapes, because the product's
     * whole argument is that lead count and lead quality are different things.
     *
     * The sixth element is a quality weight from 0 to 1: how likely a lead
     * from this campaign is to be worth a rep's time. A broad video campaign
     * on a social feed produces volume and very little intent; someone typing
     * "شقق للبيع الرياض" into Google is already shopping. A demo where every
     * campaign performs the same cannot show the one thing this product is for.
     *
     * [name, platform, adset, ad, source, quality]
     */
    public const CAMPAIGNS = [
        // Meta — the biggest spender here, and a wide spread of outcomes.
        ['حملة إطلاق مشروع النرجس', 'meta', 'انستقرام - ستوري', 'فيديو جولة المشروع', 'paid_social', 0.30],
        ['حملة تمويل عقاري - الرياض', 'meta', 'فيسبوك - خبر', 'كاروسيل الوحدات', 'paid_social', 0.55],
        ['ريتارجتنج - زوار الموقع', 'meta', 'انستقرام - ريلز', 'عرض الدفعة الأولى', 'retargeting', 0.70],
        ['حملة الشقق العائلية', 'meta', 'فيسبوك - ماركت بليس', 'صور الوحدات', 'paid_social', 0.35],

        // Snapchat — the volume play: cheap reach, thin intent.
        ['حملة سناب - شقق التحلية', 'snapchat', 'سناب - ستوري', 'إعلان فيديو عمودي', 'paid_social', 0.12],
        ['سناب - عرض نهاية العام', 'snapchat', 'سناب - ديسكفر', 'موشن جرافيك العرض', 'paid_social', 0.08],

        // TikTok — the most volume, the least qualified.
        ['حملة تيك توك - فلل الياسمين', 'tiktok', 'تيك توك - For You', 'جولة 360', 'paid_social', 0.10],
        ['تيك توك - جولة المشروع', 'tiktok', 'تيك توك - اهتمامات', 'فيديو من المالك', 'paid_social', 0.06],

        // Google — fewer leads, and the ones that buy.
        ['بحث جوجل - شقق للبيع الرياض', 'google_ads', 'بحث - كلمات شراء', 'نص إعلاني A', 'search', 0.75],
        ['بحث جوجل - فلل جدة', 'google_ads', 'بحث - كلمات الموقع', 'نص إعلاني B', 'search', 0.65],
        ['حملة الشبكة الإعلانية', 'google_ads', 'عرض - اهتمامات عقارية', 'بانر المشروع', 'display', 0.15],

        // LinkedIn — investors: very few, very serious.
        ['حملة لينكدإن - مستثمرين', 'linkedin', 'لينكدإن - اهتمامات', 'عرض العائد الاستثماري', 'paid_social', 0.80],

        // Owned channels.
        ['نموذج الموقع الإلكتروني', 'website', 'الموقع', 'نموذج الاهتمام', 'website', 0.60],
        ['صفحة هبوط - عرض رمضان', 'landing_page', 'صفحة هبوط', 'نموذج العرض', 'landing_page', 0.45],
    ];

    /** Notes a sales rep would plausibly type. */
    public const NOTES = [
        'العميل مهتم بالوحدات ذات الثلاث غرف، طلب جدول الدفعات.',
        'تم إرسال بروشور المشروع عبر واتساب. سيعاود الاتصال بعد مراجعة الأسرة.',
        'يحتاج تمويل عقاري، تم توجيهه لقسم التمويل.',
        'الميزانية أقل من المعروض، تم عرض خيار بديل في مشروع آخر.',
        'مهتم جداً وطلب زيارة الموقع نهاية الأسبوع.',
        'العميل خارج المملكة حالياً، يفضل التواصل بعد أسبوعين.',
        'تمت زيارة المعرض، أعجبه النموذج B. بانتظار موافقة البنك.',
        'طلب تفاصيل الضمانات ومدة التسليم.',
        'يبحث عن استثمار للإيجار وليس للسكن.',
        'تم تحويله لمدير المبيعات لمناقشة خصم الدفعة الكاملة.',
    ];

    /** Follow-up subjects, the kind that fill a rep's day. */
    public const FOLLOW_UP_NOTES = [
        'الاتصال لتأكيد موعد الزيارة',
        'متابعة بخصوص جدول الدفعات',
        'إرسال عرض السعر النهائي',
        'التأكد من حالة طلب التمويل',
        'مكالمة متابعة بعد الزيارة',
        'إرسال مخططات الوحدة المطلوبة',
        'متابعة بعد عرض الخصم',
        'تأكيد الحضور لتوقيع العقد',
    ];

    /** [name, developer, location, starting price] */
    public const PROJECTS = [
        ['مشروع النرجس ريزيدنس', 'رواسي للتطوير', 'حي النرجس - الرياض', 1250000],
        ['فلل الياسمين', 'رواسي للتطوير', 'حي الياسمين - الرياض', 2400000],
        ['أبراج التحلية', 'رواسي للتطوير', 'شارع التحلية - جدة', 980000],
        ['كمباوند القيروان', 'رواسي للتطوير', 'حي القيروان - الرياض', 3100000],
        ['شقق الشاطئ', 'رواسي للتطوير', 'الكورنيش - الخبر', 870000],
    ];

    /** [name, email, phone] — the sales floor. */
    public const SALES_TEAM = [
        ['فهد العتيبي', 'fahad@rawasi.test', '+966551000002'],
        ['نورة الشمري', 'noura@rawasi.test', '+966551000003'],
        ['خالد الدوسري', 'khalid@rawasi.test', '+966551000004'],
        ['ريم الغامدي', 'reem@rawasi.test', '+966551000005'],
        ['ماجد الزهراني', 'majed@rawasi.test', '+966551000006'],
    ];

    /**
     * Short exchanges in the shape a real one takes: the rep opens, the buyer
     * answers with the thing that actually decides the sale — budget, timing,
     * financing — and the rep moves toward a viewing.
     *
     * Each entry is [outbound, inbound, ...] in order.
     */
    public const CONVERSATIONS = [
        [
            'مرحبًا، معك {{agent}} من {{company}} بخصوص اهتمامك بمشروع {{project}}. هل أقدر أساعدك بأي استفسار؟',
            'أهلًا، نعم مهتم. كم سعر الشقق ثلاث غرف؟',
            'تبدأ من 980 ألف ريال، ومتاح تمويل بنكي حتى 90%. تحب أرسل لك المخططات؟',
            'نعم من فضلك. وهل فيه وحدات جاهزة للتسليم؟',
            'نعم، الدور الثالث والرابع جاهز. متى يناسبك تزور المعرض؟',
            'يمكن نهاية الأسبوع إن شاء الله',
        ],
        [
            'مرحبًا، معك {{agent}} من {{company}}. وصلنا طلبك بخصوص {{project}}، متى يناسبك أتواصل معك؟',
            'بعد العصر أفضل',
            'تمام، سأتصل بك غدًا بعد العصر. شكرًا لك.',
        ],
        [
            'مرحبًا، معك {{agent}} من {{company}} بخصوص {{project}}.',
            'شكرًا، لكن ميزانيتي أقل من المطلوب حاليًا',
            'أقدّر وضوحك. لدينا وحدات أصغر تبدأ من 740 ألف، أرسلها لك؟',
            'نعم ممكن',
        ],
        [
            'مرحبًا، هذه تفاصيل الوحدات المتاحة في {{project}}. أخبرني أي نوع يناسبك.',
            'هل التمويل متاح لغير السعوديين؟',
            'نعم، مع بعض البنوك. تحب أحوّلك لمستشار التمويل لدينا؟',
            'نعم من فضلك',
        ],
        [
            'مرحبًا، نؤكد موعدك لزيارة {{project}} غدًا الساعة 5 مساءً. بانتظارك.',
            'تمام، سأكون هناك',
        ],
        [
            'مرحبًا، حاولت التواصل معك بخصوص {{project}}. متى يناسبك أتواصل معك؟',
        ],
    ];
}
