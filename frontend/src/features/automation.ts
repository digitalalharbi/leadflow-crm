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

export interface AutomationRun {
  id: string
  rule: string | null
  trigger: string
  outcome: 'applied' | 'skipped' | 'failed'
  reason: string | null
  applied_actions: string[]
  created_at: string
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
