import { useQuery } from '@tanstack/react-query'
import { apiGet } from '@/lib/api'

export type Light = 'ok' | 'watch' | 'bad'

export interface OpsPulseData {
  answers: {
    leads_today: number
    interested: number
    hot: number
    needs_follow_up: number
    /** Automation handed it over and nobody has picked it up. */
    needs_human: number
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

export interface OperationsHomeData {
  campaign_health: { classes: Partial<Record<'excellent' | 'healthy' | 'watch' | 'at_risk' | 'critical', number>>; total: number; worst: { id: string; name: string; platform: string; score: number; class: string; action: string | null }[] } | null
  tracking_health: { platforms: { platform: string; status: 'healthy' | 'attention' | 'risk' | 'idle'; connected: boolean; success_rate: number | null }[]; counts: Record<string, number> } | null
  automation_health: { handled_pct: number | null; failed: number; needs_human: number; rules_active: number } | null
  sales_ranking: { id: string | null; name: string; leads: number; won: number; won_vs_expected: number; small_sample: boolean }[] | null
  revenue_trend: { days: { date: string; revenue: number }[]; total: number; previous_total: number } | null
  tops: {
    source: { platform: string; leads: number; won: number; share: number | null } | null
    campaign: { id: string; name: string; platform: string; reservations: number; won: number; leads: number } | null
    partner: { id: string; name: string; won: number; leads: number } | null
  }
}

export function useOperationsHome() {
  return useQuery({ queryKey: ['operations-home'], queryFn: () => apiGet<OperationsHomeData>('/dashboard/home'), refetchInterval: 120_000, staleTime: 60_000 })
}
