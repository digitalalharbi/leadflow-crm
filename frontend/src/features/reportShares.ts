import { apiDelete, apiGet, apiPatch, apiPost, http } from '@/lib/api'

export const REPORT_TEMPLATES = ['executive', 'marketing', 'sales', 'client', 'partner', 'management'] as const
export type ReportTemplate = (typeof REPORT_TEMPLATES)[number]

export const REPORT_SECTIONS = ['kpi_volume', 'kpi_cost', 'kpi_revenue', 'funnel', 'trend', 'platforms', 'campaigns', 'team'] as const
export type ReportSection = (typeof REPORT_SECTIONS)[number]

/** The sections that show money: the ones to be careful about handing to a client or a broker. */
export const MONEY_SECTIONS: ReportSection[] = ['kpi_cost', 'kpi_revenue']

export type ShareStatus = 'active' | 'expired' | 'revoked'

export interface ReportShare {
  id: string
  name: string
  template: ReportTemplate
  sections: Record<ReportSection, boolean>
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
}

export interface ShareDirectory {
  shares: ReportShare[]
  templates: { key: ReportTemplate; sections: Record<ReportSection, boolean> }[]
  sections: ReportSection[]
  money: ReportSection[]
}

export function fetchShares() {
  return apiGet<ShareDirectory>('/report-shares')
}

export interface ShareInput {
  name: string
  template: ReportTemplate
  sections?: Partial<Record<ReportSection, boolean>>
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

/** What a shared link shows. Only the sections that are on are present. */
export interface SharedReportData {
  report: { name: string; template: ReportTemplate; days: number; from: string; to: string; generated_at: string; currency: string }
  sections: ReportSection[]
  workspace: { name: string | null; color: string | null; logo_url: string | null }
  kpis?: Partial<Record<'leads' | 'interested' | 'visits' | 'reservations' | 'won' | 'spend' | 'cpl' | 'cac' | 'roas' | 'revenue', number | null>>
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
export async function fetchSharedReport(token: string, password?: string): Promise<SharedReportResult> {
  const response = await http.get(`/shared/reports/${token}`, {
    headers: password ? { 'X-Share-Password': password } : undefined,
    validateStatus: () => true,
  })

  if (response.status === 200) return { kind: 'ok', data: response.data.data as SharedReportData }
  if (response.status === 401) return { kind: 'password', name: response.data?.data?.name ?? null, workspace: response.data?.data?.workspace ?? { name: null, color: null } }
  if (response.status === 410) return { kind: 'expired' }

  return { kind: 'gone' }
}
