import { useQuery } from '@tanstack/react-query'
import { apiGet } from '@/lib/api'

export type AttentionKey =
  | 'stalled'
  | 'priority'
  | 'wrong_fit'
  | 'sla_breached'
  | 'overdue_follow_ups'
  | 'hot'
  | 'appointments_awaiting_outcome'
  | 'unassigned'
  | 'automation_failures'
  | 'message_failures'

export interface AttentionRow {
  key: AttentionKey
  severity: 'critical' | 'warning'
  count: number
  href: string
  /** Only on `sla_breached`: the workspace's own first-contact target. */
  minutes?: number
}

export interface Recommendation {
  id: string
  name: string
  project: string | null
  temperature: string | null
  score: number | null
  action: string
  urgency: 'critical' | 'high' | 'medium' | 'low'
  reason: string
}

export interface CommandCenterData {
  open_leads: number
  attention: AttentionRow[]
  recommended: Recommendation[]
  appointments_today: Array<{
    id: string
    lead_id: string | null
    lead: string | null
    at: string
    location: string | null
  }>
  workload: Array<{ id: string; name: string; open: number; overdue: number; hot: number }>
  target_minutes: number
}

/**
 * What needs a person right now. A minute, not two: this is the panel people
 * glance at between calls, and a stale "3 leads waiting" is worse than none.
 */
export function useCommandCenter() {
  return useQuery({
    queryKey: ['command-center'],
    queryFn: () => apiGet<CommandCenterData>('/dashboard/command'),
    refetchInterval: 60_000,
  })
}
