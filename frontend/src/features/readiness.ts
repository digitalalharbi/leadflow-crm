import { apiGet, apiPost } from '@/lib/api'
import type { IntegrationState } from '@/features/integrationState'

/**
 * Is this deployment live, and what is it waiting for.
 *
 * Three states, each a claim with its own burden of proof — see the server's
 * `ProductionReadiness`. The one worth remembering here: `live_verified` is
 * never reachable from this screen. Commissioning can move a row to `ready`,
 * and only something the other end did moves it further.
 */
export type ReadinessStatus = 'ready' | 'external_verification_required' | 'live_verified'

export interface CommissioningStep {
  key: string
  /** null is "skipped, and here is why" — not a pass. */
  ok: boolean | null
  detail: string
}

/** One fact a rung stands on, with the time it was observed when there is one. */
export interface Evidence {
  key: string
  ok: boolean
  at: string | null
  detail: string | null
}

export type DeploymentKind = 'local' | 'staging' | 'production'

export interface ReadinessItem {
  key: 'vps' | 'domain' | 'google' | 'whatsapp' | 'twilio' | 'ads' | 'email' | 'claude' | 'calendar'
  status: ReadinessStatus
  /** The rung of the six-state ladder, computed from `evidence`. */
  state: IntegrationState
  evidence: Evidence[]
  blocker: string | null
  checks: Array<{ key: string; ok: boolean }>
  commissionable: boolean
  last_commissioning: { ok: boolean; at: string; steps: CommissioningStep[] } | null
  children?: Array<{ key: string; status: ReadinessStatus }>
}

/** Where a piece of infrastructure stands — three words, three burdens of proof. */
export type InfraState = 'not_configured' | 'ready' | 'live_verified'

export interface InfraItem {
  key: 'database' | 'redis' | 'queues' | 'horizon' | 'backups' | 'logs' | 'error_tracking'
  state: InfraState
  /** The same six-rung ladder as every other row. */
  ladder: IntegrationState
  evidence: Evidence[]
  status: ReadinessStatus
  /** When it was last *seen* — a heartbeat's own time, or now for a probe. */
  last_check: string
  last_error: string | null
  /** A key naming what a person has to do next; null when nothing is waiting. */
  required_action: string | null
  facts: Record<string, string | number | boolean | null | string[]>
}

export interface ReadinessReport {
  scope: 'workspace' | 'platform'
  /** What this server is — from what is observable, not from what a document says. */
  deployment: { kind: DeploymentKind; url: string; environment: string; https: boolean; public_host: boolean }
  /** Every row on the screen, counted by rung. */
  ladder: Record<IntegrationState, number>
  items: ReadinessItem[]
  infrastructure: InfraItem[]
  infrastructure_summary: Partial<Record<InfraState, number>>
  summary: Record<ReadinessStatus, number>
}

export function fetchReadiness(platform: boolean) {
  return apiGet<ReadinessReport>(platform ? '/super-admin/readiness' : '/readiness')
}

export function commission(key: ReadinessItem['key']) {
  return apiPost<{
    result: { ok: boolean; steps: CommissioningStep[] }
    readiness: ReadinessReport
  }>(`/readiness/${key}/commission`)
}
