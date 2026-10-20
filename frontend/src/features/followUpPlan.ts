import { apiGet, apiPost } from '@/lib/api'

export type PlanStatus = 'active' | 'paused' | 'stopped' | 'completed'
export type PlanDecision = 'continue' | 'escalate' | 'stop'
export type PlanChannel = 'whatsapp' | 'email' | 'call' | 'team'
export type StepState = 'planned' | 'done' | 'skipped' | 'cancelled' | 'failed'

/** One rung of the week: what happens, on which channel, when, and why. */
export interface PlanStep {
  key: 'now' | '15m' | '4h' | '24h' | '3d' | '7d'
  at: string
  channel: PlanChannel
  action: { type: string; purpose?: string } | null
  state: StepState
  /** A reason key, optionally followed by `+quiet_hours`. */
  why: string
  scheduled_action_id: number | null
  /** WhatsApp could not go, so it went by the other channel. */
  fallback: boolean
}

export interface FollowUpPlan {
  status: PlanStatus
  mode: 'automated' | 'human'
  decision: PlanDecision
  reason: string
  steps: PlanStep[]
  next: PlanStep | null
  progress: { done: number; total: number }
  can: { pause: boolean; resume: boolean; stop: boolean; reschedule: boolean }
}

type Response = { follow_up: FollowUpPlan | null }

const base = (leadId: string) => `/leads/${leadId}/follow-up-plan`

export const pauseFollowUp = (leadId: string) => apiPost<Response>(`${base(leadId)}/pause`)
export const resumeFollowUp = (leadId: string) => apiPost<Response>(`${base(leadId)}/resume`)
export const stopFollowUp = (leadId: string) => apiPost<Response>(`${base(leadId)}/stop`)
export const rescheduleFollowUp = (leadId: string, at: string) => apiPost<Response>(`${base(leadId)}/reschedule`, { at })
export const fetchFollowUp = (leadId: string) => apiGet<Response>(base(leadId))
