import { useQuery } from '@tanstack/react-query'
import { apiGet, apiPost } from '@/lib/api'

export type DeliveryStatus = 'delivered' | 'bounced' | 'queued' | 'sending' | 'sent' | 'logged' | 'failed'
export type MailProviderKey = 'log' | 'smtp' | 'ses' | 'resend'

export interface MailProviderState {
  key: MailProviderKey
  /** Has everything it needs to really send. */
  configured: boolean
  /** A message it accepts leaves the building (false for the log, which only writes it down). */
  delivers: boolean
  requires: string[]
}

export interface DeliveryRow {
  id: string
  kind: string
  to: string
  subject: string
  provider: MailProviderKey
  status: DeliveryStatus
  attempts: number
  max_attempts: number
  error: string | null
  created_at: string | null
  sent_at: string | null
  next_attempt_at: string | null
  /** The failure log: every attempt, and what the provider said. */
  log: { attempt: number; ok: boolean; retryable: boolean; error: string | null; at: string | null }[]
}

export interface DeliveryStatusData {
  provider: MailProviderState
  providers: MailProviderState[]
  counts: Partial<Record<DeliveryStatus, number>>
  deliveries: DeliveryRow[]
}

export function useEmailDeliveries(enabled = true) {
  return useQuery({ queryKey: ['email-deliveries'], queryFn: () => apiGet<DeliveryStatusData>('/email-deliveries'), enabled, refetchInterval: 30_000 })
}

export const retryDelivery = (id: string) => apiPost<{ delivery: DeliveryRow }>(`/email-deliveries/${id}/retry`)

export const emailReport = (shareId: string, body: { recipients: string[]; message?: string }) =>
  apiPost<{ deliveries: { id: string; to: string; status: DeliveryStatus; provider: MailProviderKey }[] }>(`/report-shares/${shareId}/email`, body)
