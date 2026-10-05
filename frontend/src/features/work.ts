import { apiGet, apiPost } from '@/lib/api'
import type { LeadListItem } from '@/types/api'

/**
 * What the server decided to do next with one lead, and why.
 *
 * The reason travels with the action deliberately. A screen that says "call
 * this one" and nothing else is a screen reps learn to argue with; one that
 * says "no answer in 3 hours, SLA is 30 minutes" is one they act on.
 */
export type NextActionKind =
  | 'call_now'
  | 'reply_now'
  | 'follow_up'
  | 'book_meeting'
  | 'schedule_follow_up'
  | 'assign'
  | 'wait'
  | 'none'

export type Urgency = 'critical' | 'high' | 'medium' | 'low' | 'none'

export interface NextAction {
  action: NextActionKind
  urgency: Urgency
  reason: string
  due_in_minutes: number | null
}

export interface NextInQueue {
  lead: LeadListItem | null
  action: NextAction | null
  remaining: number
}

export function fetchNextInQueue() {
  return apiGet<NextInQueue>('/work/next')
}

export interface WorkSummary {
  open: number
  uncontacted: number
  overdue_first_contact: number
  overdue_follow_ups: number
  hot: number
}

export function fetchWorkSummary() {
  return apiGet<WorkSummary>('/work/summary')
}

/** Logs the outcome of working a lead, which is also what advances the queue. */
export function logContact(
  leadId: string,
  payload: { channel: string; outcome?: string; note?: string; next_due_at?: string | null },
) {
  return apiPost<{ lead: LeadListItem }>(`/leads/${leadId}/contact`, payload)
}
