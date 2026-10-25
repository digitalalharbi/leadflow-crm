import { apiGet, apiPatch, apiPost, apiPut, http } from '@/lib/api'
import type { ReportShare, ShareStatus } from './reportShares'

/** One address handed to one client: its own password, expiry and protection. */
export interface ReportLink {
  id: string
  label: string | null
  client_name: string | null
  client_email: string | null
  url: string
  status: ShareStatus
  is_active: boolean
  has_password: boolean
  require_otp: boolean
  restricted: boolean
  allowed_emails: string[]
  allowed_domains: string[]
  allow_export: boolean
  expires_at: string | null
  revoked_at: string | null
  view_count: number
  last_viewed_at: string | null
  created_at: string
}

/** A link seen from the client access center: with the report and projects it opens. */
export interface ClientLink extends ReportLink {
  report: { id: string; name: string; state: 'draft' | 'live' | 'archived' }
  projects: { id: string; name: string }[]
}

export interface LinkInput {
  label?: string | null
  client_name?: string | null
  client_email?: string | null
  password?: string | null
  expires_at?: string | null
  is_active?: boolean
  allow_export?: boolean
  require_otp?: boolean
  allowed_emails?: string[] | null
  allowed_domains?: string[] | null
}

export interface ReportNoteItem { id: string; kind: 'executive' | 'marketing' | 'sales' | 'period'; body: string; revision: number; author: string | null; at: string }
export interface ReportRevisionItem { revision: number; changed: string[]; configuration: Record<string, unknown>; by: string | null; at: string }
export interface ReportActivityItem { action: string; by: string | null; meta: Record<string, unknown>; at: string }

export const fetchLinks = (id: string) => apiGet<{ links: ReportLink[] }>(`/report-shares/${id}/links`)
export const addLink = (id: string, payload: LinkInput) => apiPost<{ link: ReportLink }>(`/report-shares/${id}/links`, payload)
export const updateLink = (id: string, link: string, payload: LinkInput) => apiPatch<{ link: ReportLink }>(`/report-shares/${id}/links/${link}`, payload)
export const revokeLink = (id: string, link: string) => apiPost<{ link: ReportLink }>(`/report-shares/${id}/links/${link}/revoke`)
export const regenerateLink = (id: string, link: string) => apiPost<{ link: ReportLink }>(`/report-shares/${id}/links/${link}/regenerate`)

/** A person copied or sent a link: the report's activity says it was shared. Never worth failing a copy over. */
export const markShared = (id: string, link: string) => apiPost<{ recorded: boolean }>(`/report-shares/${id}/links/${link}/shared`).catch(() => undefined)

export const fetchClientLinks = () => apiGet<{ links: ClientLink[] }>('/report-links')

export const publishReport = (id: string) => apiPost<{ state: string }>(`/report-shares/${id}/publish`)
export const archiveReport = (id: string) => apiPost<{ state: string }>(`/report-shares/${id}/archive`)
export const unarchiveReport = (id: string) => apiPost<{ state: string }>(`/report-shares/${id}/unarchive`)
export const duplicateReport = (id: string) => apiPost<{ id: string }>(`/report-shares/${id}/duplicate`)

export const fetchNotes = (id: string) => apiGet<{ notes: ReportNoteItem[]; history: ReportNoteItem[] }>(`/report-shares/${id}/notes`)
export const saveNote = (id: string, kind: ReportNoteItem['kind'], body: string) => apiPut<{ notes: ReportNoteItem[]; history: ReportNoteItem[] }>(`/report-shares/${id}/notes`, { kind, body })
export const fetchRevisions = (id: string) => apiGet<{ revisions: ReportRevisionItem[] }>(`/report-shares/${id}/revisions`)
export const fetchActivity = (id: string) => apiGet<{ activity: ReportActivityItem[] }>(`/report-shares/${id}/activity`)

/** The report as a file for the team. The server decides whether the plan and the person may have it. */
export async function exportReport(id: string, format: 'csv' | 'xlsx' | 'pdf', name: string): Promise<void> {
  const response = await http.get(`/report-shares/${id}/export`, { params: { format }, responseType: 'blob' })
  const url = URL.createObjectURL(response.data as Blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `${name}.${format}`
  link.click()
  URL.revokeObjectURL(url)
}

export type { ReportShare }
