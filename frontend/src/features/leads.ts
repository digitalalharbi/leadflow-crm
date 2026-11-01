import type { FollowUpPlan } from './followUpPlan'
import { apiDelete, apiGet, apiGetPaginated, apiPatch, apiPost, apiPut } from '@/lib/api'
import type {
  FollowUp, Lead, LeadActivity, LeadAppointment, LeadCall, LeadListItem, LeadNote, LeadStatus, NextAction,
  LeadTemperature, PaginationMeta, Project, ProjectMetrics, Ref,
} from '@/types/api'

export interface LeadFilters {
  search?: string
  status?: string[]
  temperature?: string[]
  interest_level?: string[]
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
  /** Any of these tags, without case. */
  tag?: string
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

/** What the conversation said, as structured facts — and which engine read it. */
export interface ConversationFacts {
  /** `claude`, or `rules` when Claude was not available — `note` says why. */
  engine: 'claude' | 'rules' | null
  note: 'no_api_key' | 'claude_failed' | 'claude_empty' | null
  analyzed_at: string | null
  facts: {
    intent: { value: 'ready_to_buy' | 'interested' | 'inquiring' | 'comparing' | 'callback_request' | 'not_interested' | 'wrong_contact'; confidence: number } | null
    urgency: { value: 'high' | 'medium' | 'low'; timeline: string | null } | null
    service_interest: 'villa' | 'apartment' | 'townhouse' | 'land' | 'commercial' | null
    budget: { signal: 'stated' | 'tight' | 'flexible'; min?: number; max?: number; currency?: string } | null
    appointment_intent: 'requested' | 'interested' | null
    sentiment: 'positive' | 'neutral' | 'negative' | null
    objections: string[]
    /** Real estate facts the customer volunteered. */
    transaction?: 'buy' | 'rent' | null
    payment?: 'cash' | 'financing' | null
    purpose?: 'residence' | 'investment' | null
    reservation_intent?: 'ready' | 'interested' | null
    bedrooms?: number | null
    district?: string | null
  }
}

/** What is still unknown about what a lead wants — and the one thing to ask next. */
export interface QualificationGaps {
  known: string[]
  gaps: string[]
  next: string | null
  total: number
  percent: number
}

/** What a rep needs in the first ten seconds after automation hands them a lead. */
export interface HandoffBrief {
  reason: { code: string | null; words: string }
  since: string | null
  summary: {
    project: string | null; city: string | null; platform: string | null; campaign: string | null
    budget: { min?: number; max?: number } | null; timeline: string | null; property: string | null
    score: number; temperature: string | null; priority: boolean
  }
  why_now: { signals: string[]; said: string | null }
  qualification: { status: string | null; done: number; total: number; answered: { label: string; value: string }[]; missing: string[] }
  objections: string[]
  mood: { intent: string | null; urgency: string | null; sentiment: string | null }
  engine: 'claude' | 'rules' | null
  last_conversation: { who: 'customer' | 'rep' | 'automation'; channel: string; text: string; at: string | null }[]
  next_action: { action: string; urgency: string; reason: string } | null
  /** How interested they are, and how long they may still wait. */
  interest?: { level: string; sla: { state: 'ok' | 'due_soon' | 'breached' | 'done'; minutes_left: number } | null } | null
  /** Who spoke last, and when. */
  last_activity?: { who: 'customer' | 'rep' | 'automation'; at: string | null } | null
}

export function analyzeConversation(leadId: string) {
  return apiPost<{ conversation: ConversationFacts }>(`/leads/${leadId}/conversation/analyze`, {})
}

export interface LeadDetailResponse {
  lead: Lead
  conversation?: ConversationFacts
  qualification_gaps?: QualificationGaps
  /** Present while a person holds a lead automation handed over. */
  handoff?: HandoffBrief | null
  /** The next week of automatic follow-up: null for a lead that predates the planner. */
  follow_up?: FollowUpPlan | null
  intelligence?: LeadIntelligence
  activities: LeadActivity[]
  notes: LeadNote[]
  follow_ups: FollowUp[]
  next_action: NextAction
  /** Where the reply clock stands for an interested lead (the same one the work queue shows); null for a lead with no clock. */
  sla?: { level: string; state: 'ok' | 'due_soon' | 'breached' | 'done'; target_minutes: number; since: string; deadline: string; minutes_left: number } | null
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

export function setLeadTags(id: string, tags: string[]) {
  return apiPut<{ lead: Lead }>(`/leads/${id}/tags`, { tags })
}

export function fetchLeadTags() {
  return apiGet<{ tags: Array<{ tag: string; count: number }> }>('/leads/tags')
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

/** A person's word on how interested a lead is; null hands the decision back to the numbers. */
export function setInterest(id: string, override: 'interested' | 'not_interested' | null) {
  return apiPost<{ lead: Lead }>(`/leads/${id}/interest`, { override })
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
