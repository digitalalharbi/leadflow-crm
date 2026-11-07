/**
 * The marketing page's words, in both languages.
 *
 * Only the official lines are used for the name, the headline, the description, the journey and the three
 * promises (brand kit). Everything the preview shows is labelled as sample data on the page itself.
 */
export interface LandingCopy {
  nav: { features: string; journey: string; vision: string; login: string; trial: string; menu: string }
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
  cta: { start: string; dashboard: string; contact: string }
  /** What search engines and link previews say about the product. */
  metaDescription: string
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
    nav: { features: 'المزايا', journey: 'الرحلة', vision: 'الرؤية', login: 'تسجيل الدخول', trial: 'ابدأ مع مهتم', menu: 'القائمة' },
    badge: 'منصة تشغيل التسويق والمبيعات العقارية',
    headline: 'حوّل الاهتمام إلى فرصة',
    sub: 'كل من اهتم بمشروعك هو فرصة قريبة. «مهتم» تجعلك أول من يصل إليه، وآخر من يفرّط فيه.',
    ctaTrial: 'ابدأ مع مهتم',
    ctaLogin: 'تسجيل الدخول',
    trialNote: 'ابدأ تجربة مجانية لشركتك، أو سجّل الدخول إن كانت لديك مساحة. للاستفسار تواصل معنا.',
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
    cta: { start: 'ابدأ مع مهتم', dashboard: 'فتح لوحة التحكم', contact: 'تواصل معنا' },
    metaDescription: 'مهتم منصة تربط الحملات التسويقية والعملاء المحتملين وفريق المبيعات والمتابعة والزيارات والحجوزات والإيرادات في مكان واحد، بعزل كامل لكل شركة ومشروع.',
    footer: {
      tagline: 'حوّل الاهتمام إلى فرصة',
      links: { privacy: 'الخصوصية', terms: 'الشروط', deletion: 'حذف البيانات', security: 'الأمان', contact: 'التواصل', about: 'عن مهتم' },
      copyright: '© 2026 مهتم · mohtam.pro',
    },
  },
  en: {
    nav: { features: 'Features', journey: 'The journey', vision: 'Vision', login: 'Sign In', trial: 'Get Started with Mohtam', menu: 'Menu' },
    badge: 'The marketing and sales operating platform for real estate',
    headline: 'Turn Interest Into Opportunity',
    sub: 'Everyone who showed interest in your project is an opportunity within reach. Mohtam makes you the first to reach them, and the last to let them go.',
    ctaTrial: 'Get Started with Mohtam',
    ctaLogin: 'Sign In',
    trialNote: 'Start a free trial for your company, or sign in if you already have a workspace. Questions? Contact us.',
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
    cta: { start: 'Get Started with Mohtam', dashboard: 'Open Dashboard', contact: 'Contact us' },
    metaDescription: 'Mohtam connects marketing campaigns, leads, sales teams, follow-ups, viewings, reservations and revenue in one platform, with complete isolation for every company and project.',
    footer: {
      tagline: 'Turn Interest Into Opportunity',
      links: { privacy: 'Privacy', terms: 'Terms', deletion: 'Data deletion', security: 'Security', contact: 'Contact', about: 'About' },
      copyright: '© 2026 Mohtam · mohtam.pro',
    },
  },
}
