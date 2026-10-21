import { apiGet, apiPatch } from '@/lib/api'
import type { Source } from '@/features/anomalySettings'

/** What a workspace may let run at once. Everything else always waits for a person. */
export const SELF_APPROVE_ACTIONS = ['pause', 'resume', 'update_budget'] as const
export type SelfApproveAction = (typeof SELF_APPROVE_ACTIONS)[number]

export interface SelfApprovalData {
  enabled: boolean
  enabled_source: Source
  actions: SelfApproveAction[]
  actions_source: Source
  /** The most a budget may move, in percent, and still run at once. Zero: a budget change always waits. */
  max_budget_change_pct: number
  max_budget_change_pct_source: Source
  configurable: SelfApproveAction[]
  always_manual: string[]
  project: { id: string; name: string } | null
}

export const fetchSelfApproval = (projectId?: string) => apiGet<SelfApprovalData>('/settings/self-approval', projectId ? { project_id: projectId } : undefined)

/** A null clears the setting: the workspace's value for a project, the built-in default for the workspace. */
export const saveSelfApproval = (changes: { enabled?: boolean | null; actions?: SelfApproveAction[] | null; max_budget_change_pct?: number | null }, projectId?: string) =>
  apiPatch<SelfApprovalData>('/settings/self-approval', { ...changes, project_id: projectId })
