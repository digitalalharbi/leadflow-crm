import { apiGet, apiPost } from '@/lib/api'

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

export interface ReadinessItem {
  key: 'vps' | 'domain' | 'google' | 'whatsapp' | 'twilio' | 'ads'
  status: ReadinessStatus
  blocker: string | null
  checks: Array<{ key: string; ok: boolean }>
  commissionable: boolean
  last_commissioning: { ok: boolean; at: string; steps: CommissioningStep[] } | null
  children?: Array<{ key: string; status: ReadinessStatus }>
}

export interface ReadinessReport {
  scope: 'workspace' | 'platform'
  items: ReadinessItem[]
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
