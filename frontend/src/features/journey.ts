import { apiGet } from '@/lib/api'

export const JOURNEY_KINDS = [
  'source', 'automation', 'whatsapp', 'email', 'file', 'call', 'appointment', 'followup', 'stage', 'score', 'revenue', 'note',
] as const

export type JourneyKind = (typeof JOURNEY_KINDS)[number]

/** Who did it: the customer, a rule, a colleague, or the system. */
export type JourneyActor = 'customer' | 'automation' | 'person' | 'system'

export interface JourneyEntry {
  id: string
  kind: JourneyKind
  /** The activity type, when the entry comes from the activity log. */
  type: string | null
  at: string
  title: string
  detail: string | null
  actor: JourneyActor
  status: string | null
  by: string | null
  meta: Record<string, unknown> | null
}

export interface JourneyResponse {
  entries: JourneyEntry[]
  counts: Partial<Record<JourneyKind, number>>
}

export function fetchJourney(leadId: string) {
  return apiGet<JourneyResponse>(`/leads/${leadId}/journey`)
}
