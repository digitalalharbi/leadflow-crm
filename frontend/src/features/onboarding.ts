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

export function runTestLead() {
  return apiPost<TestLeadReport>('/onboarding/test-lead')
}

export function fetchTestLeadTrace(id: string) {
  return apiGet<TestLeadTrace>(`/onboarding/test-lead/${id}`)
}

export function deleteTestLead(id: string) {
  return apiDelete(`/onboarding/test-lead/${id}`)
}
