import { apiDelete, apiGet, apiPatch, apiPost, http } from '@/lib/api'

export const REPORT_TEMPLATES = ['executive', 'executive_management', 'marketing', 'sales', 'client', 'investor', 'partner', 'management'] as const
export type ReportTemplate = (typeof REPORT_TEMPLATES)[number]

export const REPORT_SECTIONS = [
  'kpi_volume', 'kpi_cost', 'kpi_revenue', 'funnel', 'interested', 'qualification', 'visits', 'reservations', 'trend',
  'platforms', 'campaigns', 'attribution', 'team', 'automation', 'tracking_health', 'insights', 'recommendations', 'statement',
  'exec_marketing', 'exec_sales', 'exec_automation', 'exec_tracking',
] as const
export type ReportSection = (typeof REPORT_SECTIONS)[number]

/** The sections that show money: the ones to be careful about handing to a client or a broker. */
export const MONEY_SECTIONS: ReportSection[] = ['kpi_cost', 'kpi_revenue', 'exec_marketing', 'exec_sales']

/** The reports a workspace starts from, ready-made. */
export const REPORT_PRESETS = ['executive_management', 'marketing', 'sales', 'client', 'investor', 'partner'] as const
export type ReportPresetKey = (typeof REPORT_PRESETS)[number]

export interface ReportPreset {
  key: ReportPresetKey
  template: ReportTemplate
  days: number
  compare: boolean
  refresh_seconds: number
  audience: string
  /** A partner report is about one partner. */
  needs_partner: boolean
  sections: Record<ReportSection, boolean>
  layout: LayoutItem[]
}

/** How a report sends itself, at a glance; the schedules are managed on their own. */
export interface ScheduleSummary { id: string; frequency: 'daily' | 'weekly' | 'monthly'; enabled: boolean; next_run_at: string | null; recipients: number }

export type ShareStatus = 'active' | 'paused' | 'archived' | 'expired' | 'revoked'

/** Who sees a section: anyone with the link, or only the team (in the preview, never on the shared page). */
export type SectionVisibility = 'public' | 'team'

/** One line of a report's layout: the order is the array's, and each line says what it shows and to whom. */
export interface LayoutItem {
  key: ReportSection
  on: boolean
  visible_to: SectionVisibility
  /** A period of its own for this section; null follows the report. */
  days: number | null
}

export interface ReportShare {
  id: string
  name: string
  template: ReportTemplate
  sections: Record<ReportSection, boolean>
  /** Every section in the order it is shown, with whether it is on and who sees it. */
  layout: LayoutItem[]
  /** Put the previous period beside every figure. */
  compare: boolean
  /** How often an open page refreshes itself, in seconds. */
  refresh_seconds: number
  days: number
  project: { id: string; name: string } | null
  /** For a partner's report: whose leads it is, and whether they may see revenue. */
  partner?: { id: string; name: string; show_revenue: boolean } | null
  url: string
  status: ShareStatus
  kind?: 'live' | 'snapshot'
  snapshot?: { generated_at: string; days: number; project: string | null; generated_by: string } | null
  has_password: boolean
  expires_at: string | null
  revoked_at: string | null
  view_count: number
  last_viewed_at: string | null
  created_at: string
  schedules?: ScheduleSummary[]
}

export interface ShareDirectory {
  shares: ReportShare[]
  templates: { key: ReportTemplate; sections: Record<ReportSection, boolean>; layout: LayoutItem[] }[]
  sections: ReportSection[]
  money: ReportSection[]
  presets: ReportPreset[]
  /** The sections this person may not put in a report: they cannot see what they are built from. */
  unavailable: ReportSection[]
}

export function fetchShares() {
  return apiGet<ShareDirectory>('/report-shares')
}

export interface ShareInput {
  name: string
  preset?: ReportPresetKey
  template: ReportTemplate
  sections?: Partial<Record<ReportSection, boolean>>
  layout?: LayoutItem[]
  compare?: boolean
  refresh_seconds?: number
  days?: number
  project_id?: string | null
  partner_id?: string | null
  password?: string | null
  expires_at?: string | null
}

export function createShare(payload: ShareInput) {
  return apiPost<{ share: ReportShare }>('/report-shares', payload)
}

export function updateShare(id: string, payload: Partial<ShareInput>) {
  return apiPatch<{ share: ReportShare }>(`/report-shares/${id}`, payload)
}

export const shareAction = (id: string, action: 'pause' | 'resume' | 'archive' | 'duplicate' | 'snapshot') => apiPost<{ share: ReportShare }>(`/report-shares/${id}/${action}`)

export function revokeShare(id: string) {
  return apiPost<{ share: ReportShare }>(`/report-shares/${id}/revoke`)
}

export function regenerateShare(id: string) {
  return apiPost<{ share: ReportShare }>(`/report-shares/${id}/regenerate`)
}

export function deleteShare(id: string) {
  return apiDelete<{ deleted: boolean }>(`/report-shares/${id}`)
}

export interface ShareAnalytics {
  total: number
  unique: number
  last_viewed_at: string | null
  daily: { date: string; views: number }[]
  recent: { at: string; device: string }[]
}

export function fetchShareAnalytics(id: string) {
  return apiGet<ShareAnalytics>(`/report-shares/${id}/analytics`)
}

/** What a finding says, as a catalogue key and the numbers to put in it — the page words it in the reader's language. */
export interface Finding {
  key: string
  severity?: 'info' | 'warning' | 'critical'
  values: Record<string, string | number | null>
  /** Where in the product the person goes to act on it; absent on a public link. */
  link?: string | null
}

/** One page of the management report. */
export interface ExecPage<K extends string> {
  figures: Record<K, number | null>
  previous: Partial<Record<K, number | null>> | null
}

export type KpiKey = 'leads' | 'interested' | 'visits' | 'reservations' | 'won' | 'spend' | 'cpl' | 'cac' | 'roas' | 'revenue'

/** What a shared link shows. Only the sections that are on are present, in the order the owner chose. */
export interface SharedReportData {
  report: { name: string; template: ReportTemplate; days: number; from: string; to: string; generated_at: string; currency: string; compare: boolean; refresh_seconds: number; previous?: { from: string; to: string } }
  sections: ReportSection[]
  workspace: { name: string | null; color: string | null; logo_url: string | null }
  kpis?: Partial<Record<KpiKey, number | null>>
  /** The same figures a period earlier, when the report compares. */
  previous?: Partial<Record<KpiKey, number | null>>
  interested?: { levels: { key: 'hot' | 'interested' | 'visit_ready' | 'reservation_ready'; count: number }[]; total: number; late: number; on_time_pct: number | null }
  qualification?: { qualified: number; partial: number; unqualified: number; total: number; rate: number | null }
  visits?: { booked: number; completed: number; no_show: number; cancelled: number; completion_rate: number | null; by_day?: { date: string; count: number }[] }
  reservations?: { pending: number; confirmed: number; converted: number; cancelled: number; conversion_rate: number | null }
  automation?: { handled_pct: number | null; automated: number; human: number; needs_human: number }
  /** The management report's four pages: each a group of figures, with the period before beside it when the report compares. */
  exec_marketing?: ExecPage<'spend' | 'leads' | 'cpl' | 'qualified' | 'cpql' | 'interested' | 'visits' | 'reservations' | 'cac' | 'roas'>
  exec_sales?: ExecPage<'assigned' | 'contacted' | 'response_minutes' | 'follow_up_completion' | 'visits' | 'reservations' | 'won' | 'revenue' | 'close_rate' | 'sla'>
  exec_automation?: ExecPage<'coverage' | 'success' | 'failures' | 'needs_human'>
  exec_tracking?: ExecPage<'attribution' | 'utm_health' | 'unattributed' | 'offline_sent' | 'offline_failed' | 'offline_success'>
  tracking_health?: { platform: string; state: string; sent: number; failed: number; last_sent_at: string | null }[]
  attribution?: { models: Record<'first' | 'last' | 'linear' | 'position', { key: string; label: string | null; leads: number; interested: number; visits: number; reservations: number; won: number; revenue?: number }[]> }
  insights?: Finding[]
  recommendations?: Finding[]
  /** A partner's own statement: terms, what they brought, what they are owed and have been paid. */
  statement?: {
    partner: string
    terms: { type: 'percentage' | 'fixed'; percent: number | null; fixed: number | null; agreed: boolean }
    funnel: { leads: number; qualified: number; visits: number; reservations: number; won: number }
    commission: { due: number | null; paid: number; balance: number | null }
    deals: { won_at: string | null; commission: number | null; value?: number }[]
    payments: { paid_on: string; amount: number; reference: string | null }[]
    revenue?: number
  }
  /** The period before, day by day, when the report compares. */
  previous_trend?: { date: string; leads: number; interested: number; visits: number; reservations: number; won: number; spend?: number; revenue?: number }[]
  funnel?: { key: 'leads' | 'qualified' | 'interested' | 'visits' | 'reservations' | 'won'; count: number; rate: number | null; lost: number | null; cost?: number | null }[]
  trend?: { date: string; leads: number; interested: number; visits: number; reservations: number; won: number; spend?: number; revenue?: number }[]
  platforms?: { key: string; leads: number; spend?: number; revenue?: number }[]
  campaigns?: { name: string; platform: string; leads: number; qualified: number; interested: number; visits: number; reservations: number; won: number; spend?: number | null; cpl?: number | null; cac?: number | null; roas?: number | null; revenue?: number }[]
  team?: { name: string; leads: number; interested: number; visits: number; reservations: number; won: number }[]
}

export type SharedReportResult =
  | { kind: 'ok'; data: SharedReportData }
  | { kind: 'password'; name: string | null; workspace: { name: string | null; color: string | null } }
  | { kind: 'expired' }
  | { kind: 'gone' }

/**
 * Opens a shared report. Public — no session — so it asks the server directly and reads the
 * status itself: "a password is needed" and "this ended" are screens, not errors.
 */
export async function fetchSharedReport(token: string, password?: string, refresh = false): Promise<SharedReportResult> {
  const response = await http.get(`/shared/reports/${token}`, {
    // An open page asking again is the same view, not another one.
    headers: { ...(password ? { 'X-Share-Password': password } : {}), ...(refresh ? { 'X-Share-Refresh': '1' } : {}) },
    validateStatus: () => true,
  })

  if (response.status === 200) return { kind: 'ok', data: response.data.data as SharedReportData }
  if (response.status === 401) return { kind: 'password', name: response.data?.data?.name ?? null, workspace: response.data?.data?.workspace ?? { name: null, color: null } }
  if (response.status === 410) return { kind: 'expired' }

  return { kind: 'gone' }
}

/** The report as the team sees it, including the sections kept for the team. */
export function fetchSharePreview(id: string) {
  return apiGet<SharedReportData & { workspace?: SharedReportData['workspace'] }>(`/report-shares/${id}/preview`)
}
