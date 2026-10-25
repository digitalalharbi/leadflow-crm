import { apiDelete, apiGet, apiPatch, apiPost, http } from '@/lib/api'

export const REPORT_TEMPLATES = ['executive', 'executive_management', 'marketing', 'sales', 'full_funnel', 'project', 'campaign', 'team', 'client', 'investor', 'partner', 'management'] as const
export type ReportTemplate = (typeof REPORT_TEMPLATES)[number]

export const REPORT_SECTIONS = [
  'kpi_volume', 'kpi_cost', 'kpi_revenue', 'funnel', 'interested', 'qualification', 'visits', 'reservations', 'trend',
  'platforms', 'campaigns', 'attribution', 'team', 'automation', 'tracking_health', 'insights', 'recommendations', 'statement',
  'exec_marketing', 'exec_sales', 'exec_automation', 'exec_tracking',
  'marketing', 'sources', 'sales', 'lead_quality', 'loss', 'projects', 'units', 'tracking_hub', 'revenue', 'attention',
] as const
export type ReportSection = (typeof REPORT_SECTIONS)[number]

/** The sections that show money: the ones to be careful about handing to a client or a broker. */
export const MONEY_SECTIONS: ReportSection[] = ['kpi_cost', 'kpi_revenue', 'exec_marketing', 'exec_sales', 'marketing', 'revenue']

/** Templates that need the plan's advanced-templates feature. */
export const ADVANCED_TEMPLATES: ReportTemplate[] = ['full_funnel', 'project', 'campaign', 'team', 'management']

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

export type ShareStatus = 'active' | 'expired' | 'revoked' | 'inactive'

export type ReportState = 'draft' | 'live' | 'archived'

export const REPORT_PERIODS = ['today', 'yesterday', 'last_7', 'last_14', 'last_30', 'this_month', 'previous_month', 'quarter', 'custom'] as const
export type ReportPeriodKey = (typeof REPORT_PERIODS)[number] | 'last_n'

export const COMPARE_MODES = ['previous_period', 'previous_month', 'custom'] as const
export type CompareMode = (typeof COMPARE_MODES)[number]

export const ATTRIBUTION_MODELS = ['first', 'last', 'linear', 'position'] as const
export type AttributionModel = (typeof ATTRIBUTION_MODELS)[number]

/** What narrows a report beyond its projects: by public id, never by an internal one. */
export interface ReportFilters { platforms?: string[]; campaigns?: string[]; sources?: string[]; sales_users?: string[] }

/** The report's look. Text and https images only; "hide_mohtam" needs the plan. */
export interface ReportBranding { name?: string; color?: string; logo_url?: string; project_logo_url?: string; footer?: string; hide_mohtam?: boolean }

export interface ReportOptions { expose_team?: boolean; show_unit_prices?: boolean; show_names?: boolean; show_owner?: boolean }

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
  state: ReportState
  client_name: string | null
  owner: { id: string; name: string } | null
  period: ReportPeriodKey
  period_from: string | null
  period_to: string | null
  compare_mode: CompareMode
  compare_from: string | null
  compare_to: string | null
  attribution_model: AttributionModel
  currency: string | null
  filters: ReportFilters
  branding: ReportBranding
  options: ReportOptions
  revision: number
  projects: { id: string; name: string }[]
  published_at: string | null
  archived_at: string | null
  links_count: number | null
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
  has_password: boolean
  expires_at: string | null
  revoked_at: string | null
  view_count: number
  last_viewed_at: string | null
  created_at: string
  updated_at: string
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
  state?: 'draft' | 'live'
  client_name?: string | null
  project_ids?: string[]
  period?: ReportPeriodKey | null
  period_from?: string | null
  period_to?: string | null
  compare_mode?: CompareMode
  compare_from?: string | null
  compare_to?: string | null
  attribution_model?: AttributionModel
  currency?: string | null
  filters?: ReportFilters
  branding?: ReportBranding
  options?: ReportOptions
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

export type KpiKey = 'leads' | 'qualified' | 'interested' | 'visits' | 'reservations' | 'won' | 'conversion_rate' | 'spend' | 'cpl' | 'cpa' | 'cac' | 'roas' | 'revenue'

/** The official funnel — وعي → اهتمام → تفاعل → حجز معاينة → قرار شراء → إحالة — as the API draws it. */
export type FunnelStageKey = 'awareness' | 'interest' | 'engagement' | 'booking' | 'decision' | 'referral'

export interface FunnelStage {
  key: FunnelStageKey
  label: string
  /** False when the data for the stage does not exist (no ad account, no tracked traffic): shown as "not available", never as zero. */
  available: boolean
  count: number | null
  rate: number | null
  drop_off: number | null
  previous: number | null
  change: number | null
  revenue?: number | null
  /** What one of this stage cost, when the spend is known. */
  cost?: number | null
  /** Awareness only: whose number it is — the ad platforms' or Mohtam's own measurement. */
  basis?: 'platform' | 'mohtam' | null
  reason?: string | null
  subset_of_previous?: boolean
  sub_stages: { key: string; count: number; revenue?: number | null }[]
}

export interface RealEstateFunnelData {
  stages: FunnelStage[]
  period: { from: string; to: string; days: number }
  previous_period: { from: string; to: string }
  /** One point per day (per week over a long period); awareness is null where there is nothing to chart. */
  trend: { bucket: 'day' | 'week'; points: ({ date: string; awareness: number | null } & Partial<Record<Exclude<FunnelStageKey, 'awareness'>, number>>)[] } | null
  lost: { count: number; rate: number | null }
  revenue?: number | null
  generated_at: string
}

export interface SharedNote { kind: 'executive' | 'marketing' | 'sales' | 'period'; body: string; at: string }

export interface ChannelRow { key: string; leads: number; qualified: number; interested: number; visits: number; reservations: number; won: number; visit_rate: number | null; revenue?: number; cpl?: number | null; cac?: number | null; roas?: number | null }

/** What a shared link shows. Only the sections that are on are present, in the order the owner chose. */
export interface SharedReportData {
  report: {
    name: string; template: ReportTemplate; days: number; from: string; to: string; generated_at: string; currency: string; compare: boolean; refresh_seconds: number
    previous?: { from: string; to: string }
    period?: string; attribution_model?: AttributionModel; client_name?: string | null; state?: ReportState
    projects?: { name: string }[]; owner?: string | null; revision?: number
    /** True when the numbers came out of a short-lived cache, with the age of that cache. */
    cached?: boolean; cache_age_seconds?: number
  }
  sections: ReportSection[]
  workspace: { name: string | null; color: string | null; logo_url: string | null; footer?: string | null; project_logo_url?: string | null; powered_by?: boolean }
  link?: { allow_export: boolean; exports: { csv: boolean; xlsx: boolean; pdf: boolean } }
  notes?: SharedNote[]
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
  /** Credit under the model the report was made with — always named — and, on a plan with advanced attribution, all four side by side. */
  attribution?: { model: AttributionModel; rows: AttributionRow[]; models?: Record<AttributionModel, AttributionRow[]> }
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
  funnel?: RealEstateFunnelData
  trend?: { date: string; leads: number; interested: number; visits: number; reservations: number; won: number; spend?: number; revenue?: number }[]
  platforms?: { key: string; leads: number; spend?: number; revenue?: number }[]
  /** Two blocks, never mixed: what the ad platforms reported, and what Mohtam attributed. */
  marketing?: {
    has_ad_data: boolean
    platform_reported: { key: string; spend?: number | null; impressions?: number | null; clicks?: number | null; ctr?: number | null; cpc?: number | null }[]
    mohtam_attributed: ChannelRow[]
  }
  campaigns?: { total: number; rows: CampaignRow[] }
  sources?: { rows: ChannelRow[] }
  sales?: SalesFigures
  team?: TeamRow[]
  lead_quality?: LeadQuality
  loss?: { lost: number; rate: number | null; reasons: { label: string; count: number }[]; stage: { early: number; interested: number; visit: number; reservation: number } }
  projects?: ProjectRow[]
  units?: { available: boolean; rows: { unit: string; interested: number; visits: number; reservations: number; sold: number; availability: string; revenue?: number; price?: number }[] }
  tracking_hub?: { available: boolean; link_views: number; landing_views: number; form_views: number; form_starts: number; form_submissions: number; leads: number; qualified: number; interested: number; visits: number; reservations: number; revenue?: number }
  revenue?: { deals: number; total: number | null; average_deal: number | null; by_source: { key: string; deals: number; revenue: number }[]; by_project: { name: string; revenue: number }[] }
  attention?: Finding[]
}

export interface AttributionRow { key: string; label: string | null; leads: number; qualified?: number; interested: number; visits: number; reservations: number; won: number; revenue?: number }

export interface CampaignRow {
  name: string; platform: string; status?: string | null; project?: string | null
  impressions?: number | null; clicks?: number | null; ctr?: number | null
  leads: number; qualified: number; interested: number; visits: number; reservations: number; won: number
  spend?: number | null; cpc?: number | null; cpl?: number | null; cac?: number | null; roas?: number | null; revenue?: number | null
  /** The period before, when the report compares. */
  leads_previous?: number; spend_previous?: number | null
}

export interface SalesFigures {
  received: number; assigned: number; unassigned: number; contacted: number; not_contacted: number; qualified: number; interested: number
  follow_ups: number; follow_ups_done: number; overdue_follow_ups: number; appointments: number; visits: number; reservations: number; won: number; lost: number
  avg_first_response_minutes: number | null; sla_breaches: number; sla_target_minutes: number; revenue?: number
}

export interface TeamRow {
  name: string; leads: number; contact_rate: number | null; response_minutes: number | null; follow_up_completion: number | null
  qualified: number; interested: number; visits: number; reservations: number; won: number; conversion_rate: number | null; revenue?: number
}

export interface LeadQuality {
  total: number
  interest: { high: number; medium: number; low: number } | null
  qualified: number; unqualified: number; lost: number
  budget: { key: string; count: number }[]
  purchase_timeline: { key: string; count: number }[]
  financing: { key: string; count: number }[]
  unit_preference: { key: string; count: number }[]
  project_interest: { name: string; count: number }[]
}

export interface ProjectRow {
  name: string; leads: number; sources: number; qualified: number; interested: number; visits: number; reservations: number; won: number; conversion_rate: number | null
  units: { total: number; available: number; reserved: number; sold: number } | null; spend?: number | null; revenue?: number
}

export type SharedReportResult =
  | { kind: 'ok'; data: SharedReportData }
  | { kind: 'password'; name: string | null; workspace: { name: string | null; color: string | null } }
  | { kind: 'otp'; name: string | null; workspace: { name: string | null; color: string | null } }
  | { kind: 'expired' }
  | { kind: 'gone' }

/** What a visitor has proven so far: the password, and the pass an emailed code earned. */
export interface ShareCredentials { password?: string; access?: string }

/**
 * Opens a shared report. Public — no session — so it asks the server directly and reads the
 * status itself: "a password is needed", "a code is needed" and "this ended" are screens, not errors.
 */
export async function fetchSharedReport(token: string, credentials: ShareCredentials = {}, refresh = false, part?: 'primary' | 'secondary'): Promise<SharedReportResult> {
  const response = await http.get(`/public/r/${token}`, {
    params: part ? { part } : undefined,
    // An open page asking again is the same view, not another one.
    headers: {
      ...(credentials.password ? { 'X-Share-Password': credentials.password } : {}),
      ...(credentials.access ? { 'X-Share-Access': credentials.access } : {}),
      ...(refresh ? { 'X-Share-Refresh': '1' } : {}),
    },
    validateStatus: () => true,
  })

  if (response.status === 200) return { kind: 'ok', data: response.data.data as SharedReportData }
  if (response.status === 401) {
    const body = response.data?.data ?? {}
    const gate = { name: body.name ?? null, workspace: body.workspace ?? { name: null, color: null } }

    return body.otp_required ? { kind: 'otp', ...gate } : { kind: 'password', ...gate }
  }
  if (response.status === 410) return { kind: 'expired' }

  return { kind: 'gone' }
}

/** The two halves of a report as one: the first screen's data, then the rest, in the order the first half named. */
export function mergeParts(primary: SharedReportData, secondary?: SharedReportData | null): SharedReportData {
  if (!secondary) return primary

  const { report: _r, sections: _s, workspace: _w, notes: _n, link: _l, ...rest } = secondary as SharedReportData & Record<string, unknown>

  return { ...primary, ...rest } as SharedReportData
}

/** Asks for a code at an email address. The answer is the same whether or not the address is allowed. */
export async function requestShareCode(token: string, email: string): Promise<void> {
  await http.post(`/public/r/${token}/code`, { email })
}

/** Trades the emailed code for a pass; null when the code is not right. */
export async function verifyShareCode(token: string, email: string, code: string): Promise<string | null> {
  const response = await http.post(`/public/r/${token}/verify`, { email, code }, { validateStatus: () => true })

  return response.status === 200 ? (response.data.data.access as string) : null
}

/** The address of a client's download of the report (CSV, Excel or PDF), with what they proved. */
export async function downloadSharedReport(token: string, format: 'csv' | 'xlsx' | 'pdf', credentials: ShareCredentials = {}): Promise<Blob> {
  const response = await http.get(`/public/r/${token}/export`, {
    params: { format },
    responseType: 'blob',
    headers: { ...(credentials.password ? { 'X-Share-Password': credentials.password } : {}), ...(credentials.access ? { 'X-Share-Access': credentials.access } : {}) },
  })

  return response.data as Blob
}

/** The report as the team sees it, including the sections kept for the team. */
export function fetchSharePreview(id: string) {
  return apiGet<SharedReportData & { workspace?: SharedReportData['workspace'] }>(`/report-shares/${id}/preview`)
}
