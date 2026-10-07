import { apiDelete, apiGet, apiGetPaginated, apiPatch, apiPost } from '@/lib/api'
import type {
  FollowUp, Lead, LeadActivity, LeadListItem, LeadNote, LeadStatus,
  LeadTemperature, PaginationMeta, Project, ProjectMetrics, Ref,
} from '@/types/api'

export interface LeadFilters {
  search?: string
  status?: string[]
  temperature?: string[]
  platform?: string[]
  project_id?: string
  assigned_to?: string
  date_from?: string
  date_to?: string
  overdue?: boolean
  unassigned?: boolean
  /** One of the data-quality issue keys; see DataQualityController. */
  data_issue?: string
  awaiting_contact?: boolean
  replied?: boolean
  campaign_name?: string
  follow_up?: string
  sort?: string
  direction?: 'asc' | 'desc'
  page?: number
  per_page?: number
}

export function fetchLeads(filters: LeadFilters) {
  return apiGetPaginated<LeadListItem[]>('/leads', filters as Record<string, unknown>) as Promise<{
    data: LeadListItem[]
    meta: PaginationMeta | undefined
  }>
}

export interface LeadDetailResponse {
  lead: Lead
  activities: LeadActivity[]
  notes: LeadNote[]
  follow_ups: FollowUp[]
}

export function fetchLead(id: string) {
  return apiGet<LeadDetailResponse>(`/leads/${id}`)
}

export function createLead(payload: Record<string, unknown>) {
  /*
   * `restored` is not the same event as `duplicate`.
   *
   * One means the lead is already in the list; the other means a lead somebody
   * deleted has just come back, with the name and notes it had. Telling a
   * person "this number already exists" about a record they cannot see is the
   * confusing half of that.
   */
  return apiPost<{ lead: Lead; duplicate: boolean; restored: boolean }>('/leads', payload)
}

export function updateLead(id: string, payload: Record<string, unknown>) {
  return apiPatch<{ lead: Lead }>(`/leads/${id}`, payload)
}

export function deleteLead(id: string) {
  return apiDelete(`/leads/${id}`)
}

export function assignLead(id: string, assignedTo: string | null) {
  return apiPost<{ lead: Lead }>(`/leads/${id}/assign`, { assigned_to: assignedTo })
}

export function changeLeadStatus(
  id: string,
  status: LeadStatus,
  extra?: { lost_reason_id?: string; lost_note?: string },
) {
  return apiPost<{ lead: Lead }>(`/leads/${id}/status`, { status, ...extra })
}

export function changeLeadTemperature(id: string, temperature: LeadTemperature) {
  return apiPost<{ lead: Lead }>(`/leads/${id}/temperature`, { temperature })
}

export function addLeadNote(id: string, body: string) {
  return apiPost<{ note: LeadNote }>(`/leads/${id}/notes`, { body })
}

export function logContact(id: string, channel: 'call' | 'whatsapp' | 'email' | 'no_answer') {
  return apiPost<{ lead: Lead }>(`/leads/${id}/contact`, { channel })
}

export function fetchProjects() {
  return apiGet<{ projects: Project[] }>('/projects').then((r) => r.projects)
}

/** Projects with the funnel metrics their cards are built from. */
export function fetchProjectsWithMetrics() {
  return apiGet<{ projects: Project[]; metrics: ProjectMetrics[] }>('/projects')
}

export function fetchAssignableUsers() {
  return apiGet<{ users: (Ref & { role: string })[] }>('/users/assignable').then((r) => r.users)
}

export function fetchLostReasons() {
  return apiGet<{ lost_reasons: { id: string; label: string; is_active: boolean }[] }>(
    '/settings',
  ).then((r) => r.lost_reasons.filter((reason) => reason.is_active))
}
