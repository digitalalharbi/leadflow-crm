/**
 * The words of the three marketing pages behind the front door: /features, /journey and /vision.
 *
 * They sell an outcome, never a mechanism. Nothing here names how the product decides, routes, scores, connects or
 * stores anything — a page a competitor could build from is a page that gave the product away. Every line answers
 * "what does a business get", in plain words. The numbers on /features are facts about the product (six stages, one
 * link, two languages), not claims about anyone's results.
 */
export interface MarketingCopy {
  cta: { title: string; body: string }
  features: {
    title: string
    sub: string
    facts: Array<{ value: number; suffix?: string; label: string }>
    items: Array<{ key: FeatureKey; title: string; body: string }>
  }
  journey: {
    title: string
    sub: string
    stages: Array<{ title: string; promise: string; outcome: string }>
    /** «المرحلة {n} من {total}» — words, so the order reads right in either direction. */
    progress: string
    values: Array<{ title: string; body: string }>
  }
  vision: {
    title: string
    statement: string
    pillars: Array<{ title: string; body: string }>
    whyTitle: string
    why: string[]
    closing: string
  }
}

export type FeatureKey = 'clarity' | 'speed' | 'decisions' | 'loss' | 'reports' | 'teams' | 'access' | 'experience'

export const MARKETING: { ar: MarketingCopy; en: MarketingCopy } = {
  ar: {
    cta: { title: 'جاهز لتحويل الاهتمام إلى فرصة؟', body: 'ابدأ مع مهتم، أو تواصل معنا لنرتّب مساحة عمل لشركتك.' },
    features: {
      title: 'قيمة تراها من اليوم الأول',
      sub: 'كل ما يهمّ نتيجته: اهتمام لا يضيع، وفريق أسرع، وقرارات أوضح.',
      facts: [
        { value: 6, label: 'مراحل واضحة لرحلة كل عميل' },
        { value: 1, label: 'رابط واحد يفهمه عميلك' },
        { value: 2, label: 'لغتان: العربية والإنجليزية' },
        { value: 24, suffix: '/7', label: 'صورة محدَّثة في أي وقت' },
      ],
      items: [
        { key: 'clarity', title: 'رؤية أوضح', body: 'أين يقف كل مشروع، في نظرة واحدة.' },
        { key: 'speed', title: 'متابعة أسرع', body: 'من يردّ أولًا يكسب، وفريقك يردّ أولًا.' },
        { key: 'decisions', title: 'قرارات أفضل', body: 'استثمر فيما يصنع النتيجة فعلًا.' },
        { key: 'loss', title: 'فرص فقد أقل', body: 'لا يُنسى مهتم، ولا تُترك فرصة في منتصف الطريق.' },
        { key: 'reports', title: 'تقارير احترافية', body: 'صورة يفهمها المدير والعميل والمستثمر.' },
        { key: 'teams', title: 'فرق أكثر تنظيمًا', body: 'كل شخص يعرف ما عليه الآن.' },
        { key: 'access', title: 'وصول أسرع للمعلومة', body: 'الإجابة حاضرة عندما تحتاجها، من أي جهاز.' },
        { key: 'experience', title: 'تجربة أرقى لعملائك', body: 'تواصل في وقته يليق باسمك.' },
      ],
    },
    journey: {
      title: 'رحلة العميل، من الاهتمام إلى النتيجة',
      sub: 'اعرف أين وصل كل عميل، وما الخطوة التي تقرّبه منك.',
      progress: 'المرحلة {n} من {total}',
      stages: [
        { title: 'وعي', promise: 'يعرف الناس مشروعك.', outcome: 'تعرف ما الذي لفت انتباههم، وتضاعف ما ينجح.' },
        { title: 'اهتمام', promise: 'يرفع أحدهم يده.', outcome: 'يصل إليك كل مهتم، فلا تضيع فرصة واحدة.' },
        { title: 'تفاعل', promise: 'يبدأ الحوار.', outcome: 'تواصل في وقته يُبقي الاهتمام حيًّا.' },
        { title: 'حجز معاينة', promise: 'يصبح الاهتمام موعدًا.', outcome: 'كل موعد خطوة واضحة نحو القرار.' },
        { title: 'قرار شراء', promise: 'تتحقق النتيجة.', outcome: 'ترى ما قاد إلى القرار، فتكرّره بثقة.' },
        { title: 'إحالة', promise: 'يعود الرضا بعملاء جدد.', outcome: 'عميل راضٍ يفتح لك الباب إلى غيره.' },
      ],
      values: [
        { title: 'وضوح المرحلة', body: 'كل عميل في مكانه الصحيح من الرحلة.' },
        { title: 'فقد أقل', body: 'ترى من يحتاج خطوة قبل أن يبتعد.' },
        { title: 'أين وصل العميل', body: 'إجابة فورية، بلا بحث ولا تخمين.' },
        { title: 'من الاهتمام إلى النتيجة', body: 'ترى التحوّل كاملًا، وتعرف قيمته.' },
      ],
    },
    vision: {
      title: 'لماذا مهتم',
      statement: 'نؤمن أن كل اهتمام يستحق أن يصبح فرصة — وأن الفرق بين مشروع ينجح وآخر يتعثّر هو ما يحدث بعد أن يرفع العميل يده.',
      pillars: [
        { title: 'الرؤية', body: 'أن لا يضيع اهتمام عميل عقاري واحد في المملكة.' },
        { title: 'القيمة', body: 'وقت أقل في البحث، ووقت أكثر مع من يستحق.' },
        { title: 'الفلسفة', body: 'البساطة أولًا: ما يحتاجه فريقك الآن، ولا شيء يشتّته.' },
      ],
      whyTitle: 'ما الذي يجعلنا مختلفين',
      why: [
        'صُمّم للسوق العقاري، وبالعربية أولًا.',
        'يبدأ فريقك العمل من اليوم الأول، بلا تعقيد.',
        'بياناتك لك وحدك، بخصوصية تامة.',
        'صورة واحدة صادقة يفهمها الجميع.',
      ],
      closing: 'كل اهتمام يستحق أن يصبح فرصة.',
    },
  },
  en: {
    cta: { title: 'Ready to turn interest into opportunity?', body: 'Get started with Mohtam, or contact us to set up a workspace for your company.' },
    features: {
      title: 'Value you see from day one',
      sub: 'Everything that matters is the result: interest that is never lost, a faster team, clearer decisions.',
      facts: [
        { value: 6, label: 'clear stages in every lead’s journey' },
        { value: 1, label: 'link your client understands' },
        { value: 2, label: 'languages: Arabic and English' },
        { value: 24, suffix: '/7', label: 'an up-to-date picture, any time' },
      ],
      items: [
        { key: 'clarity', title: 'Clearer view', body: 'Where every project stands, at a glance.' },
        { key: 'speed', title: 'Faster follow-up', body: 'Whoever answers first wins — and your team answers first.' },
        { key: 'decisions', title: 'Better decisions', body: 'Invest in what actually makes the result.' },
        { key: 'loss', title: 'Fewer lost chances', body: 'No interested lead is forgotten, none left halfway.' },
        { key: 'reports', title: 'Professional reports', body: 'A picture your management, clients and investors understand.' },
        { key: 'teams', title: 'Better-organised teams', body: 'Everyone knows what is theirs to do now.' },
        { key: 'access', title: 'Faster answers', body: 'The answer is there when you need it, on any device.' },
        { key: 'experience', title: 'A finer client experience', body: 'Timely contact that does your name justice.' },
      ],
    },
    journey: {
      title: 'The client’s journey, from interest to result',
      sub: 'Know where every lead has reached, and the step that brings them closer.',
      progress: 'Stage {n} of {total}',
      stages: [
        { title: 'Awareness', promise: 'People learn about your project.', outcome: 'You know what caught their eye, and do more of what works.' },
        { title: 'Interest', promise: 'Someone raises a hand.', outcome: 'Every interested person reaches you — not one chance is lost.' },
        { title: 'Engagement', promise: 'The conversation starts.', outcome: 'Timely contact keeps the interest alive.' },
        { title: 'Viewing booked', promise: 'Interest becomes a visit.', outcome: 'Every visit is a clear step towards the decision.' },
        { title: 'Purchase decision', promise: 'The result arrives.', outcome: 'You see what led to the decision, and repeat it with confidence.' },
        { title: 'Referral', promise: 'Satisfaction brings new clients.', outcome: 'A happy client opens the door to the next one.' },
      ],
      values: [
        { title: 'A clear stage', body: 'Every lead in its right place in the journey.' },
        { title: 'Less loss', body: 'See who needs a step before they drift away.' },
        { title: 'Where each lead is', body: 'An instant answer, no searching, no guessing.' },
        { title: 'Interest to result', body: 'See the whole change, and know what it is worth.' },
      ],
    },
    vision: {
      title: 'Why Mohtam',
      statement: 'We believe every interest deserves to become an opportunity — and that what separates a project that succeeds from one that stalls is what happens after a client raises their hand.',
      pillars: [
        { title: 'Vision', body: 'That no real-estate client’s interest in the Kingdom goes unanswered.' },
        { title: 'Value', body: 'Less time searching, more time with the people who matter.' },
        { title: 'Philosophy', body: 'Simplicity first: what your team needs now, and nothing that distracts.' },
      ],
      whyTitle: 'What makes us different',
      why: [
        'Made for real estate, Arabic first.',
        'Your team is working from day one, without complexity.',
        'Your data is yours alone, fully private.',
        'One honest picture everyone understands.',
      ],
      closing: 'Every interest deserves to become an opportunity.',
    },
  },
}
