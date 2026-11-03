import { apiDelete, apiGet, apiPatch, apiPost } from '@/lib/api'

/** A company's outbound webhooks. The signing secret is returned once, on create and on rotate. */
export const WEBHOOK_EVENTS = [
  'lead.created', 'lead.updated', 'lead.qualified', 'lead.interested', 'lead.assigned',
  'visit.created', 'reservation.created', 'sale.created', 'lead.lost',
] as const

export type WebhookEvent = (typeof WEBHOOK_EVENTS)[number]

export interface WebhookSubscriptionRow {
  id: string
  name: string
  url: string
  events: WebhookEvent[]
  is_active: boolean
  paused: boolean
  paused_reason: string | null
  consecutive_failures: number
  last_success_at: string | null
  last_failure_at: string | null
  last_error: string | null
  health: 'healthy' | 'error' | 'paused' | 'disabled' | 'awaiting'
  created_at: string
}

export interface WebhookDeliveryRow {
  id: string
  event: string
  event_id: string
  status: 'pending' | 'succeeded' | 'failed'
  attempts: number
  response_status: number | null
  error: string | null
  delivered_at: string | null
  created_at: string
}

export const fetchWebhooks = () => apiGet<WebhookSubscriptionRow[]>('/webhook-subscriptions')
export const createWebhook = (body: { name: string; url: string; events: WebhookEvent[] }) =>
  apiPost<{ subscription: WebhookSubscriptionRow; secret: string }>('/webhook-subscriptions', body)
export const updateWebhook = (id: string, body: Partial<{ name: string; url: string; events: WebhookEvent[]; is_active: boolean }>) =>
  apiPatch<WebhookSubscriptionRow>(`/webhook-subscriptions/${id}`, body)
export const rotateWebhook = (id: string) => apiPost<{ secret: string }>(`/webhook-subscriptions/${id}/rotate`)
export const deleteWebhook = (id: string) => apiDelete<null>(`/webhook-subscriptions/${id}`)
export const testWebhook = (id: string) => apiPost<WebhookDeliveryRow>(`/webhook-subscriptions/${id}/test`)
export const fetchWebhookDeliveries = (id: string) => apiGet<WebhookDeliveryRow[]>(`/webhook-subscriptions/${id}/deliveries`)
export const replayDelivery = (id: string) => apiPost<WebhookDeliveryRow>(`/webhook-deliveries/${id}/replay`)
