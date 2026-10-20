import { apiGet } from '@/lib/api'

/** The manager's one screen: arrivals, SLA, team, sources, funnel and trend. */
export interface ManagerData {
  days: number
  kpis: {
    leads_today: number
    leads_period: number
    qualified: number
    overdue_follow_ups: number
    stalled: number
    avg_response_seconds: number | null
    appointment_rate: number | null
    conversion_rate: number | null
    revenue: number
  }
  sla: {
    target_minutes: number
    compliance: { percent: number | null; within: number; total: number }
    first_automated: { median_seconds: number | null; average_seconds: number | null; samples: number }
    first_human: { median_seconds: number | null; average_seconds: number | null; samples: number }
    stages: { status: string; count: number; average_days: number }[]
    overdue_follow_ups: number
    breached_first_contact: number
    stalled: { count: number; leads: { id: string; name: string; days: number }[] }
  }
  /** How much of the work automation did, and what it cost a person. Percentages are null with no leads. */
  automation: {
    coverage_percent: number | null
    human_percent: number | null
    leads: number
    avg_seconds_to_qualify: number | null
    qualified_samples: number
    recovered: number
    needs_human: number
    lost: { total: number; no_response: number; percent: number | null }
    revenue_by_quality: { key: 'hot' | 'warm' | 'cold' | 'unrated'; deals: number; revenue: number }[]
    trend: { date: string; leads: number; automated: number; human: number }[]
  }
  funnel: { key: 'leads' | 'valid' | 'engaged' | 'qualified' | 'appointments' | 'won'; count: number }[]
  temperature: { key: 'hot' | 'warm' | 'cold'; count: number }[]
  sources: { key: string; count: number }[]
  team: { id: string; name: string; open: number; overdue: number; hot: number; won: number; revenue: number }[]
  trend: { date: string; leads: number; qualified: number; won: number }[]
}

export function fetchManager(days: number) {
  return apiGet<ManagerData>('/dashboard/manager', { days })
}
