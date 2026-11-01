import { apiDelete, apiGet, apiPost } from '@/lib/api'

export type SuppressionChannel = 'email' | 'phone'
export type SuppressionReason = 'opt_out' | 'bounced' | 'manual' | 'complaint'

export interface Suppression {
  id: string
  channel: SuppressionChannel
  value: string
  reason: SuppressionReason
  note: string | null
  created_at: string | null
}

export function fetchSuppressions(q?: string) {
  return apiGet<{ data: Suppression[]; total: number }>('/settings/suppressions', q ? { q } : undefined)
}

export function addSuppression(body: { channel: SuppressionChannel; value: string; note?: string }) {
  return apiPost<Suppression>('/settings/suppressions', body)
}

export function removeSuppression(id: string) {
  return apiDelete<void>(`/settings/suppressions/${id}`)
}
