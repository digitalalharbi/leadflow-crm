import { useQuery } from '@tanstack/react-query'
import { apiGet, apiPatch, apiPost, apiPut } from '@/lib/api'

export type ProposalStatus = 'pending' | 'approved' | 'rejected' | 'executing' | 'executed' | 'failed' | 'blocked'

export interface Proposal {
  id: string
  action: string
  platform: string
  status: ProposalStatus
  payload: Record<string, unknown>
  reason: string
  expected_impact: string | null
  proposed_by: 'agent' | 'user'
  agent: string | null
  proposer: string | null
  decider: string | null
  decided_at: string | null
  decision_note: string | null
  /** manual: a person said yes in the inbox · self: the safe self-approve mode let it through. */
  approval: 'manual' | 'self'
  policy: { allowed: boolean; blocks: string[]; warnings: string[]; limits: Record<string, unknown> }
  error: string | null
  executed_at: string | null
  created_at: string | null
  campaign: { id: string; name: string; daily_budget_minor: number | null } | null
  ad_set: string | null
  ad: string | null
}

export function useProposals(params: { status?: ProposalStatus[]; campaign_id?: string } = {}) {
  return useQuery({
    queryKey: ['proposals', params],
    queryFn: () => apiGet<{ proposals: Proposal[]; counts: Record<string, number> }>('/action-proposals', params as Record<string, unknown>),
  })
}

export function propose(body: { action: string; campaign_id?: string; ad_set_id?: string; ad_id?: string; account_id?: string; payload?: Record<string, unknown>; reason: string; expected_impact?: string }) {
  return apiPost<{ proposal: Proposal }>('/action-proposals', body)
}

export const approveProposal = (id: string) => apiPost<{ proposal: Proposal }>(`/action-proposals/${id}/approve`)
export const rejectProposal = (id: string) => apiPost<{ proposal: Proposal }>(`/action-proposals/${id}/reject`)
export const editProposal = (id: string, payload: Record<string, unknown>) => apiPatch<{ proposal: Proposal }>(`/action-proposals/${id}`, { payload })

export interface ClientRules {
  target_cpl_minor: number | null
  target_cpql_minor: number | null
  target_cpa_minor: number | null
  target_roas: number | null
  min_spend_to_judge_minor: number | null
  daily_budget_ceiling_minor: number | null
  max_budget_change_pct: number
  blackout_days: string[] | null
  forbidden_words: string[] | null
  notes: string | null
}

export function useClientRules(projectId?: string) {
  return useQuery({
    queryKey: ['client-rules', projectId ?? null],
    queryFn: () => apiGet<{ rules: ClientRules; inherited: boolean }>('/client-rules', { project_id: projectId }),
  })
}

export const saveClientRules = (rules: ClientRules, projectId?: string) =>
  apiPut<{ rules: ClientRules }>(`/client-rules${projectId ? `?project_id=${projectId}` : ''}`, rules)
