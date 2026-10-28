/** The i18n key that names each screen in the browser tab, by the first part of its address. Public pages name themselves. */
const ROUTE_TITLES: Record<string, string> = {
  dashboard: 'titles.dashboard', work: 'nav.work', leads: 'nav.leads', pipeline: 'nav.pipeline', visits: 'nav.visits',
  campaigns: 'nav.campaigns', tracking: 'nav.tracking', partners: 'nav.partners', agents: 'nav.agents', 'follow-ups': 'nav.followUps',
  companies: 'nav.companies', projects: 'nav.projects', automation: 'nav.automation', reports: 'nav.reports', 'report-library': 'nav.reportLibrary',
  'client-reports': 'nav.clientReports', audit: 'nav.audit', integrations: 'nav.integrations', users: 'nav.users', access: 'nav.access',
  settings: 'nav.settings', 'data-quality': 'nav.dataQuality', readiness: 'nav.readiness', platform: 'titles.platform',
  login: 'titles.login', invite: 'titles.invitation', i: 'titles.invitation', 'set-password': 'titles.setPassword', 'forgot-password': 'titles.forgotPassword',
  r: 'titles.report', l: 'titles.form',
}

/** The key for a path, or null for the public pages that set their own title (the marketing page and the legal pages). */
export function routeTitleKey(pathname: string): string | null {
  const parts = pathname.split('/').filter(Boolean)

  if (parts[0] === 'platform' && parts[1] === 'login') return 'titles.platformLogin'
  if (parts[0] === 'reports' && parts[1] === 'share') return 'titles.report'

  return parts.length === 0 ? null : (ROUTE_TITLES[parts[0]] ?? null)
}
