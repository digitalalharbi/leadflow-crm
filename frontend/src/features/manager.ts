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
  funnel: { key: 'leads' | 'valid' | 'engaged' | 'qualified' | 'appointments' | 'won'; count: number }[]
  temperature: { key: 'hot' | 'warm' | 'cold'; count: number }[]
  sources: { key: string; count: number }[]
  team: { id: string; name: string; open: number; overdue: number; hot: number; won: number; revenue: number }[]
  trend: { date: string; leads: number; qualified: number; won: number }[]
}

export function fetchManager(days: number) {
  return apiGet<ManagerData>('/dashboard/manager', { days })
}
