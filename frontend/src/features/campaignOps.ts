import { useQuery } from '@tanstack/react-query'
import { apiGet, apiPost } from '@/lib/api'
import type { CampaignRow, CampaignTotals } from '@/features/campaigns'
import type { Proposal } from '@/features/proposals'

export const QUALITY_CLASSES = ['excellent', 'healthy', 'watch', 'at_risk', 'critical'] as const
export type QualityClass = (typeof QUALITY_CLASSES)[number]

export type QualityComponent = 'cpl' | 'cpql' | 'cost_per_interested' | 'cost_per_visit' | 'cost_per_reservation' | 'cac' | 'roas' | 'lead_quality' | 'attribution' | 'tracking'

/** One number for "is this campaign any good", why, and the one thing to do about it. */
export interface CampaignQualityScore {
  score: number | null
  class: QualityClass | null
  confidence: 'none' | 'low' | 'medium' | 'high'
  components: { key: QualityComponent; score: number; weight: number }[]
  why: { key: QualityComponent; score: number; value: number; benchmark: number | null }[]
  action: { key: string; component: QualityComponent } | null
}

export type OperateAction = 'pause' | 'resume' | 'set_status' | 'set_budget' | 'sync'

export interface OperationsRow extends CampaignRow {
  quality: CampaignQualityScore
  pending_proposals: number
  /** What this platform lets us do to it. */
  can: OperateAction[]
  /** Which of those this person's own change runs at once (safe self-approve), by the setting in force here. */
  instant: OperateAction[]
}

export interface OperationsData {
  campaigns: OperationsRow[]
  totals: CampaignTotals
  classes: Partial<Record<QualityClass, number>>
  /** Changes are recorded against a stand-in, not sent: no account is connected and the sandbox is on. */
  sandbox: boolean
  /** The workspace's safe self-approve setting. */
  self_approval: { enabled: boolean; actions: string[]; max_budget_change_pct: number }
  currency: string
  from: string
  to: string
}

export function useOperations(params: { days: number; platform: string; status: string }) {
  return useQuery({
    queryKey: ['campaign-operations', params],
    queryFn: () => apiGet<OperationsData>('/campaigns/operations', { days: params.days, platform: params.platform || undefined, status: params.status || undefined }),
  })
}

export type OperateResult =
  | { kind: 'proposal'; proposal: Proposal }
  | { kind: 'sync'; synced: boolean; reason: string | null; sandbox: boolean }

export function operateCampaign(id: string, body: { action: OperateAction; reason?: string; status?: 'active' | 'paused'; daily_budget?: number }) {
  return apiPost<OperateResult>(`/campaigns/${id}/operate`, body)
}

export interface CampaignTrackingHealth {
  platform: { key: string; status: string; connected: boolean; receiving: boolean; last_event_at: string | null; success_rate: number | null } | null
  leads: { total: number; with_click_id: number; with_utm: number; with_link: number; traced_percent: number | null }
  links: number
  conversions: { sent: number; failed: number; pending: number }
  status: 'healthy' | 'attention' | 'risk' | 'idle'
  issues: ('platform_risk' | 'low_trace' | 'no_links' | 'conversions_failing')[]
}

export const fetchCampaignTrackingHealth = (id: string) => apiGet<CampaignTrackingHealth>(`/campaigns/${id}/tracking-health`)
