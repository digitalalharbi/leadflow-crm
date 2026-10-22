/**
 * Mirrors database/seeders/E2ESeeder.php.
 *
 * Both sides are fixed on purpose: a test that asserts against randomised demo
 * data fails for reasons that have nothing to do with the product.
 */
export const PASSWORD = 'Password123!'

/** What the tab says when a workspace has not renamed the product (the brand kit's title). */
export const PLATFORM_TITLE = 'مهتم | حوّل الاهتمام إلى فرصة'

export const ALPHA = {
  slug: 'e2e-alpha',
  // Not renamed by this workspace, so it is the product's own name.
  systemName: 'مهتم',
  companyName: 'شركة الريادة للتطوير العقاري',
  primaryColor: '#0f766e',
  admin: { email: 'admin@e2e.test', name: 'مدير الريادة' },
  manager: { email: 'manager@e2e.test', name: 'مدير مبيعات الريادة' },
  rep1: { email: 'rep1@e2e.test', name: 'مندوب أول' },
  rep2: { email: 'rep2@e2e.test', name: 'مندوب ثاني' },
  marketing: { email: 'marketing@e2e.test', name: 'مدير التسويق' },
  salesManager: { email: 'salesmgr@e2e.test', name: 'مدير المبيعات' },
  finance: { email: 'finance@e2e.test', name: 'المالية' },
  projectManager: { email: 'projectmgr@e2e.test', name: 'مدير المشروع' },
  client: { email: 'client@e2e.test', name: 'عميل المشروع' },
  broker: { email: 'broker@e2e.test', name: 'الوسيط' },
  projects: ['مشروع الاختبار الأول', 'مشروع الاختبار الثاني', 'مشروع الاختبار الثالث'],
} as const

export const BETA = {
  slug: 'e2e-beta',
  systemName: 'المدار CRM',
  companyName: 'شركة المدار القابضة',
  primaryColor: '#b91c1c',
  admin: { email: 'admin@beta-e2e.test', name: 'مدير المدار' },
  secretLead: 'سر المدار المحجوب',
} as const

/** Leads the specs drive by name. */
export const LEADS = {
  search: 'بدرية الفريدة',
  workflow: 'وليد سير العمل',
  lost: 'سعد الفقدان',
  drag: 'راكان السحب',
  activity: 'هيفاء الأنشطة',
  assign: 'بشاير الإسناد',
  chat: 'غادة المحادثة',
  qualify: 'ماجد التأهيل',

  /*
   * Two of the filler leads, named here because the journey spec needs one
   * that closed and one that did not. Their outcome is deterministic: the
   * seeder walks `statuses[i % 6]` over leads 01..30, so 10 is always won and
   * 05 is always lost.
   */
  won: 'عميل تجريبي 10',
  closedLost: 'عميل تجريبي 05',
  open: 'عميل تجريبي 01',
} as const

/**
 * Alpha's WhatsApp connection. The app secret is real, so webhook fixtures sign
 * the way Meta does; there is no access token, which keeps the mock provider in
 * play and the integration reading "configuration required".
 */
export const WHATSAPP = {
  phoneNumberId: '100000000000001',
  appSecret: 'e2e-alpha-app-secret-value',
  verifyToken: 'e2e-alpha-verify-token',
  betaPhoneNumberId: '100000000000002',
  chatLeadPhone: '966500000011',
} as const

/** Exactly how many leads each rep owns, so "sees only their own" is provable. */
export const REP1_LEAD_COUNT = 3
export const REP2_LEAD_COUNT = 2

/** Follow-ups seeded per bucket for the alpha admin. */
export const FOLLOW_UPS = { overdue: 2, today: 2, upcoming: 2 } as const
