/**
 * The marketing page's words, in both languages.
 *
 * Only the official lines are used for the name, the headline, the description, the journey and the three
 * promises (brand kit). Everything the preview shows is labelled as sample data on the page itself.
 */
export interface LandingCopy {
  nav: { features: string; how: string; reports: string; login: string; trial: string; menu: string }
  badge: string
  headline: string
  sub: string
  ctaTrial: string
  ctaLogin: string
  trialNote: string
  journeyTitle: string
  journey: [string, string, string, string, string]
  preview: {
    sample: string
    title: string
    kpis: { customers: string; interested: string; appointments: string; reservations: string; revenue: string }
    funnelTitle: string
    funnel: [string, string, string, string, string, string]
  }
  values: Array<{ title: string; body: string }>
  reports: { title: string; body: string; cta: string; sampleTitle: string; sampleNote: string }
  /** The whole path, from the campaign to the revenue. */
  chain: { title: string; steps: string[] }
  /** What the product does, in the words a client uses. */
  features: { title: string; sub: string; items: Array<{ title: string; body: string }> }
  /** The official real-estate funnel. */
  funnel: { title: string; sub: string; stages: Array<{ title: string; body: string }> }
  cta: { start: string; dashboard: string; contact: string }
  /** What search engines and link previews say about the product. */
  metaDescription: string
  closing: string
  footer: { tagline: string; links: { privacy: string; terms: string; deletion: string; security: string; contact: string; about: string }; copyright: string }
}

/*
 * What the page is for: to sell an idea, not to explain a mechanism. It says what a customer gets — attention
 * that is never lost, speed that wins, revenue they understand — and nothing about how it is done: no list of
 * platforms, no feature names, no workings. The slogan and the journey are the brand's; everything else is a
 * promise in plain words. The preview is a picture of the outcome, labelled as sample data.
 */
export const LANDING: { ar: LandingCopy; en: LandingCopy } = {
  ar: {
    nav: { features: 'المزايا', how: 'الرحلة', reports: 'الرؤية', login: 'تسجيل الدخول', trial: 'ابدأ مع مهتم', menu: 'القائمة' },
    badge: 'منصة تشغيل التسويق والمبيعات العقارية',
    headline: 'حوّل الاهتمام إلى فرصة',
    sub: 'كل من اهتم بمشروعك هو فرصة قريبة. «مهتم» تجعلك أول من يصل إليه، وآخر من يفرّط فيه.',
    ctaTrial: 'ابدأ مع مهتم',
    ctaLogin: 'تسجيل الدخول',
    trialNote: 'الدخول لمساحات العمل المسجّلة. للاستفسار عن مساحة لشركتك تواصل معنا.',
    journeyTitle: 'رحلة كل عميل',
    journey: ['وصل', 'تواصل', 'تأهّل', 'موعد', 'صفقة'],
    preview: {
      sample: 'بيانات توضيحية',
      title: 'أعمالك في نظرة',
      kpis: { customers: 'العملاء', interested: 'المهتمون', appointments: 'المواعيد', reservations: 'الحجوزات', revenue: 'الإيراد' },
      funnelTitle: 'من الاهتمام إلى الصفقة',
      funnel: ['وصل', 'تأهّل', 'مهتم', 'موعد', 'حجز', 'صفقة'],
    },
    values: [
      { title: 'لا يضيع اهتمام', body: 'كل من سأل عن مشروعك يصل إليك، من أي طريق جاء.' },
      { title: 'سرعة تصنع الفرق', body: 'من يردّ أولًا يكسب العميل. «مهتم» تجعل فريقك دائمًا أول من يردّ.' },
      { title: 'من الاهتمام إلى الإيراد', body: 'اعرف أي جهد صنع صفقة، واستثمر فيه بثقة.' },
    ],
    reports: {
      title: 'صورة واضحة في أي لحظة',
      body: 'شارك أداءك مع من يهمه برابط واحد، وبأرقام محدَّثة.',
      cta: 'عرض نموذج',
      sampleTitle: 'نموذج توضيحي',
      sampleNote: 'أرقام توضيحية لتوضيح الشكل فقط، وليست بيانات حقيقية.',
    },
    chain: { title: 'من الحملة إلى الإيراد، في مكان واحد', steps: ['الحملات التسويقية', 'الاهتمام', 'العملاء المحتملون', 'فريق المبيعات', 'المتابعة', 'حجز المعاينة', 'قرار الشراء', 'الإحالة', 'الإيرادات'] },
    features: {
      title: 'كل ما تحتاجه لتحويل الاهتمام إلى فرصة',
      sub: 'نظام واحد يربط التسويق بالمبيعات، بعزل كامل لكل شركة ولكل مشروع.',
      items: [
        { title: 'إدارة العملاء المحتملين', body: 'كل عميل بتاريخه ومصدره ومرحلته، ومن يتابعه الآن.' },
        { title: 'الحملات ومصادر العملاء', body: 'اعرف من أين جاء كل عميل وكم كلّف، لكل منصة وحملة.' },
        { title: 'التتبع والإسناد', body: 'روابط تتبع وإسناد يوضح أي مصدر صنع المعاينة والصفقة.' },
        { title: 'فريق المبيعات والمتابعة', body: 'إسناد وأوقات رد ومتابعات لا تُنسى، وأداء كل موظف.' },
        { title: 'اعمل الآن', body: 'شاشة تضع أمام كل موظف العميل التالي الذي يستحق الاتصال.' },
        { title: 'الزيارات والحجوزات', body: 'من طلب المعاينة إلى الحجز والبيع، وحالة كل وحدة.' },
        { title: 'التقارير الحية', body: 'تقارير بأرقام محدَّثة للإدارة والعملاء والمستثمرين.' },
        { title: 'رابط واحد لمتابعة المشروع', body: 'يفتح العميل رابطًا قصيرًا واحدًا ويفهم أداء مشروعه كاملًا.' },
        { title: 'تقارير التسويق والمبيعات', body: 'قوالب جاهزة: ملخص تنفيذي، تسويق، مبيعات، مسار كامل، مشروع، حملات، فريق.' },
        { title: 'عزل كامل لكل شركة ومشروع', body: 'لا يرى أحد بيانات غيره: لكل شركة ومشروع ودور صلاحياته.' },
        { title: 'مساحات عمل SaaS', body: 'باقات واشتراكات ومقاعد وصلاحيات لكل مساحة عمل.' },
        { title: 'الأتمتة', body: 'قواعد ترد وتذكّر وتنبّه، وتسلّم الإنسان عند الحاجة.' },
        { title: 'الروابط المختصرة', body: 'روابط قصيرة آمنة للتقارير والنماذج والتتبع والدعوات.' },
        { title: 'المسار العقاري', body: 'وعي ← اهتمام ← تفاعل ← حجز معاينة ← قرار شراء ← إحالة.' },
      ],
    },
    funnel: {
      title: 'المسار العقاري في مهتم',
      sub: 'مسار واحد يُقاس به كل شيء: لوحة التحكم والتقارير والملخصات.',
      stages: [
        { title: 'وعي', body: 'الظهور والنقرات وزيارات الروابط والصفحات.' },
        { title: 'اهتمام', body: 'عميل محتمل: نموذج أو رسالة أو مكالمة.' },
        { title: 'تفاعل', body: 'تواصل وتأهيل ومتابعة واهتمام.' },
        { title: 'حجز معاينة', body: 'موعد محجوز أو معاينة تمت.' },
        { title: 'قرار شراء', body: 'حجز وتفاوض وصفقة وإيراد.' },
        { title: 'إحالة', body: 'عملاء عبر شريك أو توصية أو عودة عميل.' },
      ],
    },
    cta: { start: 'ابدأ مع مهتم', dashboard: 'فتح لوحة التحكم', contact: 'تواصل معنا' },
    metaDescription: 'مهتم منصة تربط الحملات التسويقية والعملاء المحتملين وفريق المبيعات والمتابعة والزيارات والحجوزات والإيرادات في مكان واحد، بعزل كامل لكل شركة ومشروع.',
    closing: 'كل اهتمام يستحق أن يصبح فرصة.',
    footer: {
      tagline: 'حوّل الاهتمام إلى فرصة',
      links: { privacy: 'الخصوصية', terms: 'الشروط', deletion: 'حذف البيانات', security: 'الأمان', contact: 'التواصل', about: 'عن مهتم' },
      copyright: '© 2026 مهتم · mohtam.pro',
    },
  },
  en: {
    nav: { features: 'Features', how: 'The journey', reports: 'The view', login: 'Sign In', trial: 'Get Started with Mohtam', menu: 'Menu' },
    badge: 'The marketing and sales operating platform for real estate',
    headline: 'Turn Interest Into Opportunity',
    sub: 'Everyone who showed interest in your project is an opportunity within reach. Mohtam makes you the first to reach them, and the last to let them go.',
    ctaTrial: 'Get Started with Mohtam',
    ctaLogin: 'Sign In',
    trialNote: 'Sign-in is for registered workspaces. To ask about a workspace for your company, contact us.',
    journeyTitle: 'Every lead’s journey',
    journey: ['Arrived', 'Contacted', 'Qualified', 'Viewing', 'Deal'],
    preview: {
      sample: 'Sample data',
      title: 'Your business at a glance',
      kpis: { customers: 'Leads', interested: 'Interested', appointments: 'Viewings', reservations: 'Reservations', revenue: 'Revenue' },
      funnelTitle: 'From interest to deal',
      funnel: ['Arrived', 'Qualified', 'Interested', 'Viewing', 'Reserved', 'Deal'],
    },
    values: [
      { title: 'No interest is lost', body: 'Everyone who asked about your project reaches you, whichever way they came.' },
      { title: 'Speed makes the difference', body: 'Whoever answers first wins the customer. Mohtam keeps your team first to answer.' },
      { title: 'From interest to revenue', body: 'Know which effort made a deal, and invest in it with confidence.' },
    ],
    reports: {
      title: 'A clear picture, at any moment',
      body: 'Share your performance with whoever needs it, in one link, with current numbers.',
      cta: 'See a sample',
      sampleTitle: 'A sample',
      sampleNote: 'Illustrative numbers that only show the shape — not real data.',
    },
    chain: { title: 'From the campaign to the revenue, in one place', steps: ['Marketing campaigns', 'Interest', 'Leads', 'Sales team', 'Follow-up', 'Viewing booked', 'Purchase decision', 'Referral', 'Revenue'] },
    features: {
      title: 'Everything you need to turn interest into opportunity',
      sub: 'One system that joins marketing to sales, with complete isolation for every company and project.',
      items: [
        { title: 'Lead management', body: 'Every lead with its history, source, stage, and who is on it now.' },
        { title: 'Campaigns and lead sources', body: 'Know where each lead came from and what it cost, per platform and campaign.' },
        { title: 'Tracking and attribution', body: 'Tracking links and attribution that show which source made the viewing and the deal.' },
        { title: 'Sales team and follow-up', body: 'Assignment, response times and follow-ups that are not forgotten, and each person’s results.' },
        { title: 'Work Now', body: 'A screen that puts the next lead worth calling in front of every salesperson.' },
        { title: 'Viewings and reservations', body: 'From the viewing request to the reservation and the sale, and the state of every unit.' },
        { title: 'Live reports', body: 'Reports with current numbers for management, clients and investors.' },
        { title: 'One link per project', body: 'A client opens one short link and understands their whole project’s performance.' },
        { title: 'Marketing and sales reports', body: 'Ready templates: executive summary, marketing, sales, full funnel, project, campaigns, team.' },
        { title: 'Full isolation per company and project', body: 'Nobody sees anyone else’s data: every company, project and role has its own permissions.' },
        { title: 'SaaS workspaces', body: 'Plans, subscriptions, seats and permissions for each workspace.' },
        { title: 'Automation', body: 'Rules that answer, remind and alert, and hand over to a person when needed.' },
        { title: 'Short links', body: 'Short, secure links for reports, forms, tracking and invitations.' },
        { title: 'The real-estate funnel', body: 'Awareness → Interest → Engagement → Viewing → Purchase decision → Referral.' },
      ],
    },
    funnel: {
      title: 'The real-estate funnel in Mohtam',
      sub: 'One funnel that everything is measured by: the dashboard, the reports and the digests.',
      stages: [
        { title: 'Awareness', body: 'Impressions, clicks, link and page visits.' },
        { title: 'Interest', body: 'A lead: a form, a message or a call.' },
        { title: 'Engagement', body: 'Contact, qualification, follow-up, interest.' },
        { title: 'Viewing booked', body: 'A booked viewing, or one held.' },
        { title: 'Purchase decision', body: 'Reservation, negotiation, deal and revenue.' },
        { title: 'Referral', body: 'Customers through a partner, a recommendation, or one who came back.' },
      ],
    },
    cta: { start: 'Get Started with Mohtam', dashboard: 'Open Dashboard', contact: 'Contact us' },
    metaDescription: 'Mohtam connects marketing campaigns, leads, sales teams, follow-ups, viewings, reservations and revenue in one platform, with complete isolation for every company and project.',
    closing: 'Every interest deserves to become an opportunity.',
    footer: {
      tagline: 'Turn Interest Into Opportunity',
      links: { privacy: 'Privacy', terms: 'Terms', deletion: 'Data deletion', security: 'Security', contact: 'Contact', about: 'About' },
      copyright: '© 2026 Mohtam · mohtam.pro',
    },
  },
}
