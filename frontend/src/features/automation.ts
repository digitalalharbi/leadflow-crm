import { apiDelete, apiGet, apiPatch, apiPost } from '@/lib/api'

/**
 * Rules the workspace writes, and the vocabulary it may write them in.
 *
 * The vocabulary comes from the server rather than being restated here. The
 * API rejects anything outside a closed list, so a builder that offered a
 * field the server does not know would produce a rule the customer cannot
 * save — and the obvious place for those two lists to drift apart is exactly
 * here.
 */
export interface AutomationSchema {
  triggers: string[]
  fields: string[]
  operators: string[]
  actions: string[]
  stages: string[]
}

export interface RuleCondition {
  field: string
  operator: string
  value?: unknown
}

export interface RuleAction {
  type: string
  [key: string]: unknown
}

export interface AutomationRule {
  id: string
  name: string
  trigger: string
  is_enabled: boolean
  priority: number
  stops_processing: boolean
  conditions: RuleCondition[]
  actions: RuleAction[]
  times_matched: number
  last_matched_at: string | null
  runs_count: number | null
  created_at: string | null
}

export interface RuleTemplate {
  key: string
  name: string
  description: string
  trigger: string
  priority: number
  conditions: RuleCondition[]
  actions: RuleAction[]
  installed: boolean
}

export interface RulePreview {
  sampled: number
  would_match: number
  examples: { id: string; full_name: string; status: string | null }[]
}

export interface AppliedAction {
  ok: boolean
  type: string
  description: string
}

export interface AutomationRun {
  id: number
  rule: { id: string; name: string } | null
  trigger: string
  outcome: 'applied' | 'skipped' | 'failed'
  reason: string | null
  /**
   * What each action did, as the executor reported it. Objects, not strings:
   * the backend returns `{ok, type, description}` so the trace can show the
   * prose and still mark a step that failed. A declared `string[]` here type
   * checked perfectly and crashed the component at runtime.
   */
  applied_actions: AppliedAction[] | null
  correlation_id: string | null
  duration_ms: number | null
  created_at: string | null
}

export interface AutomationInsights {
  summary: { runs: number; applied: number; failed: number; actions: number; days: number }
  daily: { date: string; applied: number; skipped: number; failed: number }[]
  by_rule: { rule: string; applied: number; skipped: number; failed: number }[]
}

export function fetchInsights(days = 30) {
  return apiGet<AutomationInsights>('/automation/insights', { days })
}

export function fetchSchema() {
  return apiGet<AutomationSchema>('/automation/schema')
}

export function fetchRules() {
  return apiGet<{ rules: AutomationRule[] }>('/automation/rules')
}

export function fetchTemplates() {
  return apiGet<{ templates: RuleTemplate[] }>('/automation/templates')
}

export function installTemplate(key: string) {
  return apiPost<{ rule: AutomationRule }>(`/automation/templates/${key}`)
}

export function createRule(payload: Record<string, unknown>) {
  return apiPost<{ rule: AutomationRule }>('/automation/rules', payload)
}

export function updateRule(id: string, payload: Record<string, unknown>) {
  return apiPatch<{ rule: AutomationRule }>(`/automation/rules/${id}`, payload)
}

export function deleteRule(id: string) {
  return apiDelete(`/automation/rules/${id}`)
}

export function previewRule(id: string) {
  return apiGet<RulePreview>(`/automation/rules/${id}/preview`)
}

export function fetchRunsForLead(leadId: string) {
  return apiGet<{ runs: AutomationRun[] }>(`/leads/${leadId}/automation-runs`)
}
