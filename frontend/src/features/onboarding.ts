import { apiDelete, apiGet, apiPost } from '@/lib/api'

export interface DiagnosticStep {
  key: string
  ok: boolean
  /** What the stage actually did, in words — not a tick. */
  detail: string
}

export interface TestLeadReport {
  ok: boolean
  lead: { id: string; name: string } | null
  steps: DiagnosticStep[]
}

export interface TestLeadTrace {
  lead: {
    id: string
    name: string
    status: string | null
    score: number
    assignee: string | null
  }
  automation: { rule: string | null; outcome: string; reason: string | null }[]
}

export interface ChecklistStep {
  key: string
  done: boolean
  /** Not counted in the progress: worth doing, not required. */
  optional?: boolean
  /** Where to go to finish it — never a dead end. */
  where: string
}

export interface SetupChecklist {
  steps: ChecklistStep[]
  done: number
  total: number
  complete: boolean
}

export function fetchSetupChecklist() {
  return apiGet<SetupChecklist>('/onboarding/checklist')
}

export function runTestLead() {
  return apiPost<TestLeadReport>('/onboarding/test-lead')
}

export function fetchTestLeadTrace(id: string) {
  return apiGet<TestLeadTrace>(`/onboarding/test-lead/${id}`)
}

export function deleteTestLead(id: string) {
  return apiDelete(`/onboarding/test-lead/${id}`)
}
