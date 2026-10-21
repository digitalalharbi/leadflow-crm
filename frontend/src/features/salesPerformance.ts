import { useQuery } from '@tanstack/react-query'
import { apiGet } from '@/lib/api'

export interface RepRow {
  rank: number
  id: string | null
  name: string
  leads: number
  /** This rep's share of every lead the team was given, in percent. */
  volume_share: number | null
  /** Too few leads for a rate to mean much: ranked after those with enough. */
  small_sample: boolean
  response_minutes: number | null
  contact_rate: number | null
  sla_compliance: number | null
  interested_rate: number | null
  visit_rate: number | null
  reservation_rate: number | null
  close_rate: number | null
  follow_up_completion: number | null
  won: number
  visits: number
  reservations: number
  /** Sales above what the team's own close rate would have made of this many leads. */
  won_vs_expected: number
  /** Absent for a role that may not see revenue. */
  revenue?: number
}

export interface SalesPerformanceData {
  from: string
  to: string
  days: number
  reps: RepRow[]
  team: { leads: number; won: number; close_rate: number | null; contact_rate: number | null; sla_compliance: number | null; interested_rate: number | null; visit_rate: number | null; reservation_rate: number | null; sla_target_minutes: number }
}

export function useSalesPerformance(days: number, projectId: string) {
  return useQuery({
    queryKey: ['sales-performance', days, projectId],
    queryFn: () => apiGet<SalesPerformanceData>('/reports/sales-performance', { days, project_id: projectId || undefined }),
  })
}
