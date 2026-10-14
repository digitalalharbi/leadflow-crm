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

/** One line of the queue behind the current card. */
export interface QueuedLead {
  id: string
  name: string
  city: string | null
  status: string
  temperature: string | null
  action: string | null
  urgency: NextAction['urgency'] | null
  waiting_since: string | null
}

/** The last few things that happened to the lead on the card. */
export interface QueueTimelineEntry {
  id: string
  type: string
  actor: string | null
  at: string | null
}

export interface NextInQueue {
  lead: LeadListItem | null
  action: NextAction | null
  remaining: number
  /*
   * What is behind this card and what already happened to it. Both arrive with
   * the card because both are on the screen beside it: a rep who has to click
   * to find out why a lead is urgent has lost the time the queue was saving.
   */
  queue: QueuedLead[]
  timeline: QueueTimelineEntry[]
}

/** `skip` carries the leads passed over in this sitting, so "skip" actually
 *  moves on instead of re-serving the same lead. */
export function fetchNextInQueue(skip: string[] = []) {
  return apiGet<NextInQueue>('/work/next', skip.length ? { skip } : undefined)
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
  return apiPost<{ lead: LeadListItem; call: { id: string; status: string } | null }>(
    `/leads/${leadId}/contact`,
    payload,
  )
}

/**
 * What came of a call, once the rep knows.
 *
 * Separate from placing it because nothing is watching a rep's phone: the
 * product learns how a call went when a person says so, and a status that
 * advanced on its own would be a guess printed as a fact.
 */
export function recordCall(
  leadId: string,
  callId: string,
  payload: { status: 'answered' | 'no_answer' | 'failed' | 'completed'; outcome?: string },
) {
  return apiPost(`/leads/${leadId}/calls/${callId}`, payload)
}

/**
 * What a rep can say happened, in the order they appear on the card.
 *
 * The list is short on purpose. Every extra button is a decision taken between
 * hanging up and dialling again, and the whole screen exists to remove those.
 */
export const OUTCOMES = [
  'interested',
  'call_later',
  'no_answer',
  'meeting_booked',
  'won',
  'lost',
] as const

export type Outcome = (typeof OUTCOMES)[number]

export interface OutcomeResult {
  recorded: string
  stage_changed: boolean
  /** The next lead, in the same response — see the controller for why. */
  next: NextInQueue | null
}

export function recordOutcome(payload: {
  lead_id: string
  outcome: Outcome
  note?: string
  skip?: string[]
}) {
  return apiPost<OutcomeResult>('/work/outcome', payload)
}
