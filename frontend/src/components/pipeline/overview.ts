import { useQuery } from '@tanstack/react-query'
import { apiGet } from '@/lib/api'
import type { ClassificationKey, LeadStatus } from '@/types/api'

export interface WatchedLead {
  id: string
  name: string
  status: LeadStatus
  score: number
  classification: ClassificationKey
  project: string | null
  assignee: string | null
  idle_days: number
}

export interface PipelineOverviewData {
  currency: string
  health: {
    total: number
    open: number
    hot: number
    stalled: number
    won: number
    open_value: number
    won_value: number
    win_rate: number | null
    avg_response_seconds: number | null
    stalled_after_days: number
  }
  funnel: { key: string; count: number; keep: number | null }[]
  stages: { status: LeadStatus; total: number; value: number; avg_age_days: number | null; hot: number }[]
  classification: { key: ClassificationKey; count: number }[]
  hot: WatchedLead[]
  stalled: WatchedLead[]
  workload: { user_id: string | null; name: string; open: number; hot: number; stalled: number }[]
}

export function usePipelineOverview() {
  return useQuery({
    queryKey: ['pipeline', 'overview'],
    queryFn: () => apiGet<PipelineOverviewData>('/pipeline/overview'),
  })
}
