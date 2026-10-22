import { apiGetPaginated, apiPost } from '@/lib/api'

export type VisitStatus = 'requested' | 'confirmed' | 'rescheduled' | 'completed' | 'no_show' | 'cancelled'
export type VisitOutcome = 'interested' | 'follow_up' | 'reservation' | 'lost'

export const VISIT_OUTCOMES: VisitOutcome[] = ['interested', 'follow_up', 'reservation', 'lost']

/** Which list of visits: asked for, coming up, done and waiting for a result, missed. */
export const VISIT_BUCKETS = ['requested', 'upcoming', 'needs_result', 'no_show'] as const
export type VisitBucket = (typeof VISIT_BUCKETS)[number]

export interface Visit {
  id: string
  status: VisitStatus
  source: 'rep' | 'customer' | 'automation'
  scheduled_at: string | null
  requested_at: string | null
  duration_minutes: number
  rescheduled_count: number
  outcome: VisitOutcome | null
  location: string | null
  moves: VisitStatus[]
  is_past: boolean
  lead?: { id: string; full_name: string; phone: string | null; status: string }
  project?: { id: string; name: string } | null
  property?: { id: string; title: string | null } | null
  assignee?: { id: string; name: string } | null
}

export function fetchVisits(bucket: VisitBucket, page = 1) {
  const query = bucket === 'no_show' ? { status: 'no_show' } : { bucket }

  return apiGetPaginated<Visit[]>('/appointments', { ...query, page, per_page: 20 })
}

export function moveVisit(id: string, payload: { to: VisitStatus; scheduled_at?: string; reason?: string }) {
  return apiPost<{ appointment: Visit }>(`/appointments/${id}/transition`, payload)
}

export function recordVisitOutcome(id: string, payload: { outcome: VisitOutcome; note?: string }) {
  return apiPost<{ appointment: Visit }>(`/appointments/${id}/outcome`, payload)
}

export type ReservationStatus = 'pending' | 'confirmed' | 'cancelled' | 'expired' | 'converted'

export interface Reservation {
  id: string
  status: ReservationStatus
  amount: number | null
  expires_at: string | null
  reserved_at: string | null
  moves: ('confirm' | 'cancel' | 'convert')[]
  lead?: { id: string; full_name: string; phone: string | null }
  project?: { id: string; name: string } | null
  property?: { id: string; title: string | null; price: number | null; status: string } | null
}

export function fetchReservations(bucket: 'open' | 'all', page = 1) {
  return apiGetPaginated<Reservation[]>('/reservations', { ...(bucket === 'open' ? { bucket: 'open' } : {}), page, per_page: 20 })
}

export function actOnReservation(id: string, action: 'confirm' | 'cancel' | 'convert', payload: { reason?: string } = {}) {
  return apiPost<{ reservation: Reservation }>(`/reservations/${id}/${action}`, payload)
}
