/**
 * Mirrors database/seeders/E2ESeeder.php.
 *
 * Both sides are fixed on purpose: a test that asserts against randomised demo
 * data fails for reasons that have nothing to do with the product.
 */
export const PASSWORD = 'Password123!'

export const ALPHA = {
  slug: 'e2e-alpha',
  systemName: 'ألفا CRM',
  companyName: 'شركة ألفا للتطوير العقاري',
  primaryColor: '#0f766e',
  admin: { email: 'admin@e2e.test', name: 'مدير ألفا' },
  manager: { email: 'manager@e2e.test', name: 'مدير مبيعات ألفا' },
  rep1: { email: 'rep1@e2e.test', name: 'مندوب أول' },
  rep2: { email: 'rep2@e2e.test', name: 'مندوب ثاني' },
  projects: ['مشروع الاختبار الأول', 'مشروع الاختبار الثاني', 'مشروع الاختبار الثالث'],
} as const

export const BETA = {
  slug: 'e2e-beta',
  systemName: 'بيتا CRM',
  companyName: 'شركة بيتا القابضة',
  primaryColor: '#b91c1c',
  admin: { email: 'admin@beta-e2e.test', name: 'مدير بيتا' },
  secretLead: 'سر بيتا المحجوب',
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
