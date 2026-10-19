import { apiDelete, apiGet, apiGetPaginated, apiPatch, apiPost } from '@/lib/api'
import type {
  FollowUp, Lead, LeadActivity, LeadAppointment, LeadCall, LeadListItem, LeadNote, LeadStatus, NextAction,
  LeadTemperature, PaginationMeta, Project, ProjectMetrics, Ref,
} from '@/types/api'

export interface LeadFilters {
  search?: string
  status?: string[]
  temperature?: string[]
  classification?: string[]
  platform?: string[]
  project_id?: string
  assigned_to?: string
  date_from?: string
  date_to?: string
  overdue?: boolean
  priority?: boolean
  wrong_fit?: boolean
  unassigned?: boolean
  /** One of the data-quality issue keys; see DataQualityController. */
  data_issue?: string
  awaiting_contact?: boolean
  replied?: boolean
  /** A built-in list: needs_human, no_reply, high_value, new_today, qualified or stalled. */
  view?: string
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

/** One of the seven numbers: 0–100, and where it sits. */
export interface IntelMetric {
  value: number
  level: 'high' | 'medium' | 'low'
}

/**
 * How a lead is doing, in seven numbers. Computed on the server from facts the
 * product already holds; `reason` is the few words that explain it.
 */
export interface LeadIntelligence {
  health: IntelMetric
  intent: IntelMetric
  engagement: IntelMetric
  response: IntelMetric
  staleness: IntelMetric & { days: number }
  contactability: IntelMetric & { channels: { whatsapp: boolean; email: boolean; call: boolean } }
  completeness: IntelMetric & { missing: string[] }
  reason: string | null
  /** Interactions per day for the last two weeks, oldest first. Absent in lists. */
  trend: number[] | null
}

export interface LeadDetailResponse {
  lead: Lead
  intelligence?: LeadIntelligence
  activities: LeadActivity[]
  notes: LeadNote[]
  follow_ups: FollowUp[]
  next_action: NextAction
  /** What the customer did that makes now the moment: replied, opened the brochure… */
  signals?: string[]
  calls: LeadCall[]
  appointments: LeadAppointment[]
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
  /**
   * `deal_value` is optional even on a win. A sale recorded without its value
   * is still a sale, and refusing to close one until somebody knows the final
   * figure is how a CRM ends up with a stage people route around.
   */
  extra?: { lost_reason_id?: string; lost_note?: string; deal_value?: number },
) {
  return apiPost<{ lead: Lead }>(`/leads/${id}/status`, { status, ...extra })
}

export function changeLeadTemperature(id: string, temperature: LeadTemperature) {
  return apiPost<{ lead: Lead }>(`/leads/${id}/temperature`, { temperature })
}

export function addLeadNote(id: string, body: string) {
  return apiPost<{ note: LeadNote }>(`/leads/${id}/notes`, { body })
}

/**
 * `bridge` means the workspace's telephony is ringing the rep's own phone;
 * `manual` means the rep dials. A fallback reason is present when the first was
 * wanted and could not happen, so the screen can say why instead of the button
 * appearing to do nothing.
 */
export interface PlacedCall {
  id: string
  status: string
  mode: 'bridge' | 'manual'
  fallback_reason: string | null
}

export function logContact(id: string, channel: 'call' | 'whatsapp' | 'email' | 'no_answer') {
  return apiPost<{ lead: Lead; call: PlacedCall | null }>(`/leads/${id}/contact`, { channel })
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

/** A set of filters somebody saved to have one press away. */
export interface SavedView {
  id: string
  name: string
  filters: Partial<LeadFilters>
}

export function fetchSavedViews() {
  return apiGet<{ views: SavedView[] }>('/leads/saved-views')
}

export function saveView(name: string, filters: Partial<LeadFilters>) {
  return apiPost<{ view: SavedView }>('/leads/saved-views', { name, filters })
}

export function deleteSavedView(id: string) {
  return apiDelete<{ deleted: boolean }>(`/leads/saved-views/${id}`)
}

/** A record that may be the same person as the lead on screen. */
export interface DuplicateCandidate {
  id: string
  name: string
  phone: string | null
  email: string | null
  status: string | null
  platform: string | null
  campaign: string | null
  created_at: string | null
  /** Why it was suggested: provider_lead_id, email or phone. Never a name. */
  reasons: Array<'provider_lead_id' | 'email' | 'phone'>
}

export function fetchDuplicates(leadId: string) {
  return apiGet<{ duplicates: DuplicateCandidate[] }>(`/leads/${leadId}/duplicates`)
}

export function mergeLead(leadId: string, duplicateId: string) {
  return apiPost<{ lead: Lead; moved: Record<string, number> }>(`/leads/${leadId}/merge`, { duplicate_id: duplicateId })
}
