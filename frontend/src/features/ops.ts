import { apiGet } from '@/lib/api'

export type Light = 'ok' | 'watch' | 'bad'

export interface OpsPulseData {
  answers: {
    leads_today: number
    interested: number
    hot: number
    needs_follow_up: number
    sla_late: number
    visits_today: number
    reservations_pending: number
    won_today: number
    /** Absent when this role may not see revenue. */
    revenue_today?: number
  }
  /** Null for a person who sees only their own work: the machinery's health is the team's business. */
  health: Record<'automation' | 'tracking' | 'campaigns' | 'backlog', { state: Light; count: number }> | null
}

export function fetchOps() {
  return apiGet<OpsPulseData>('/dashboard/ops')
}
