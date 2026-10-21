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
  condition_matches?: string[]
  actions: string[]
  stages: string[]
  /**
   * The values a field accepts, where they are a closed set.
   *
   * Only some fields appear here — a city or a campaign name has no list. The
   * ones that do get a picker rather than a text box, because a condition on
   * `last_outcome` typed as "no answer" instead of `no_answer` saves, runs,
   * matches nothing and reports no error.
   */
  field_values?: Record<string, string[]>
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
  /** auto: do it · recommend: tell the owner · monitor: only record it. */
  mode?: 'auto' | 'recommend' | 'monitor'
  template_key?: string | null
  /** How it last ran, for the table's result column. */
  last_run?: { outcome: 'applied' | 'skipped' | 'failed'; at: string } | null
  /** The project its conditions name, if any. */
  project?: string | null
  priority: number
  stops_processing: boolean
  conditions: RuleCondition[]
  /** 'all' (every condition) or 'any' (one is enough). */
  condition_match?: string
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

/** "If this rule had run over the last seven days" — nothing is executed. */
export interface RuleSimulation {
  days: number
  mode: string
  trigger_events: number
  capped: boolean
  leads_affected: number
  messages: { whatsapp: number; email: number; files: number; total: number; held: Record<string, number> }
  stage_changes: { total: number; to: Record<string, number> }
  escalations: number
  notifications: number
  other: Record<string, number>
  /** Of the leads affected: those that did qualify, and what the workspace's own history suggests. */
  qualified: { already: number; estimated: number | null; rate_with_message: number | null; rate_without: number | null; reason: 'not_enough_history' | null }
  by_day: { date: string; leads: number; messages: number }[]
  examples: { id: string; name: string; status: string | null; at: string; would: string[] }[]
  basis: 'history'
}

export type SimulationDays = 7 | 30

export function simulateRule(id: string, days: SimulationDays = 7) {
  return apiGet<{ simulation: RuleSimulation }>(`/automation/rules/${id}/simulate`, { days })
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

/** An action a rule promised for later, and has not done yet. */
export interface ScheduledAction {
  id: string
  rule_name: string
  type: string
  run_at: string
  status: 'pending' | 'done' | 'cancelled' | 'failed'
  outcome: string | null
  correlation_id: string | null
}

export function fetchRunsForLead(leadId: string) {
  return apiGet<{ runs: AutomationRun[]; scheduled: ScheduledAction[] }>(
    `/leads/${leadId}/automation-runs`,
  )
}

export function cancelScheduledAction(id: string) {
  return apiDelete(`/scheduled-actions/${id}`)
}

/**
 * Copying a rule is how a second one gets written: the fields that matter are
 * the conditions, and retyping six of them to change one is where the mistake
 * goes in.
 */
export function duplicateRule(id: string) {
  return apiPost<{ rule: AutomationRule }>(`/automation/rules/${id}/duplicate`, {})
}

/* ----------------------------------------------------------- the centre */

export interface SummaryCard {
  value: number
  previous: number | null
  change: number | null
}

export interface AutomationSummary {
  days: number
  cards: Record<'all_actions' | 'auto_contacted' | 'follow_ups' | 'qualified' | 'appointments' | 'needs_human' | 'failed' | 'inactive_rules', SummaryCard>
  success_rate: number | null
  qualification: Record<string, number>
  follow_up_trend: { date: string; value: number }[]
  human_vs_automated: { date: string; automated: number; human: number }[]
  funnel: { key: string; count: number }[]
}

export function fetchAutomationSummary(days = 7) {
  return apiGet<AutomationSummary>('/automation/summary', { days })
}

export type HistoryResult = 'completed' | 'waiting' | 'failed' | 'skipped' | 'needs_human' | 'recovered'

export interface HistoryEntry {
  id: string
  at: string | null
  lead: { id: string; name: string; score: number } | null
  rule: string | null
  rule_id: string | null
  action: string
  action_words: string
  channel: 'whatsapp' | 'email' | 'team' | 'call' | null
  mode: string
  before: string | null
  after: string | null
  result: HistoryResult
  description: string | null
}

export function fetchHistory(params: { result?: HistoryResult | ''; page?: number; rule?: string; lead?: string }) {
  return apiGet<{ entries: HistoryEntry[]; meta: { current_page: number; last_page: number; total: number } }>(
    '/automation/history',
    Object.fromEntries(Object.entries(params).filter(([, v]) => v !== undefined && v !== '')),
  )
}

export interface ScoringValue {
  value: number
  default: number
  custom: boolean
}

export function fetchProjectScoring(projectId: string) {
  return apiGet<{ scoring: Record<string, ScoringValue> }>(`/projects/${projectId}/scoring`)
}

export function updateProjectScoring(projectId: string, values: Record<string, number | null>) {
  return apiPatch<{ scoring: Record<string, ScoringValue> }>(`/projects/${projectId}/scoring`, values)
}

export interface RoutineRow {
  key: string
  kind: 'system' | 'agent'
  /** A word for system routines (continuous, hourly…), a cron line for agents'. */
  cadence: string
  last_run_at: string | null
  status: 'ok' | 'failed' | 'not_run'
  agent: string | null
  is_active?: boolean
}

export function fetchRoutines() {
  return apiGet<{ routines: RoutineRow[] }>('/automation/routines')
}

export interface PackStep {
  key:
    | 'service' | 'language' | 'brochure' | 'whatsapp_template' | 'email_template' | 'qualification' | 'sales_team'
    | 'sla' | 'scoring' | 'cadence' | 'quiet_hours'
  done: boolean
  detail: string | null
}

export interface AutomationPackStatus {
  steps: PackStep[]
  done: number
  total: number
  journey_on: boolean
  /** Whether automation may contact this project's leads at all. */
  active: boolean
  settings: { cadence: Cadence; sla_minutes: number | null; quiet_hours: { enabled: boolean; start?: string; end?: string } | null }
}

export type Cadence = 'gentle' | 'standard' | 'intensive'

export interface PackAnswers {
  service?: string
  language?: string
  cadence?: Cadence
  sla_minutes?: number | null
  quiet_hours?: { enabled: boolean; start?: string; end?: string } | null
}

export function fetchAutomationPack(projectId: string) {
  return apiGet<{ pack: AutomationPackStatus }>(`/projects/${projectId}/automation-pack`)
}

export function completeAutomationPack(projectId: string, answers: PackAnswers) {
  return apiPost<{ pack: AutomationPackStatus }>(`/projects/${projectId}/automation-pack`, answers)
}

/** One press: complete the pack, switch the journey on, and let this project's leads be contacted. */
export function activateAutomationPack(projectId: string, answers: { service?: string; language?: string } = {}) {
  return apiPost<{ pack: AutomationPackStatus }>(`/projects/${projectId}/automation-pack/activate`, answers)
}

export function pauseAutomationPack(projectId: string) {
  return apiPost<{ pack: AutomationPackStatus }>(`/projects/${projectId}/automation-pack/pause`, {})
}

/** Every line the product draws, as the project sees it: the value in force and where it comes from. */
export const THRESHOLD_KEYS = ['hot_from', 'warm_from', 'priority_from', 'high_value_from', 'sla_minutes', 'stalled_after_days', 'max_contacts_per_day', 'cooldown_minutes', 'sla_hot_minutes', 'sla_interested_minutes', 'sla_visit_minutes', 'sla_reservation_minutes'] as const
export type ThresholdKey = (typeof THRESHOLD_KEYS)[number]

export interface ThresholdValue {
  value: number
  source: 'project' | 'workspace' | 'default'
  workspace: number
  default: number
}

export type Thresholds = Record<ThresholdKey, ThresholdValue>

export function fetchProjectThresholds(projectId: string) {
  return apiGet<{ thresholds: Thresholds }>(`/projects/${projectId}/thresholds`)
}

/** A null clears the override and puts the workspace's value back. */
export function updateProjectThresholds(projectId: string, values: Partial<Record<ThresholdKey, number | null>>) {
  return apiPatch<{ thresholds: Thresholds }>(`/projects/${projectId}/thresholds`, values)
}
