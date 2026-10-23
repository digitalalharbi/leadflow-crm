import { apiGet, apiPatch } from '@/lib/api'

export type AlertCategory = 'new_lead' | 'interested_lead' | 'hot_lead' | 'visit_requested' | 'reservation_intent' | 'sla_breach' | 'automation_failure' | 'high_value' | 'tracking_failure' | 'budget_anomaly'
export type AlertMode = 'instant' | 'daily' | 'weekly' | 'off'
export type DigestKind = 'daily_sales' | 'weekly_marketing' | 'weekly_executive' | 'monthly_executive'

/** The six that are worth a WhatsApp message to a member of staff: the critical ones. */
export type StaffCategory = 'hot_lead' | 'visit_requested' | 'reservation_intent' | 'sla_breach' | 'high_value' | 'automation_failure'
export type StaffMode = 'instant' | 'off'

export interface WhatsappPrefs {
  enabled: boolean
  alerts: Record<StaffCategory, StaffMode>
  /** Their own window, or — until they set one — the workspace's. */
  quiet: { enabled: boolean; start: string; end: string; source: 'own' | 'workspace' }
  categories: StaffCategory[]
  modes: StaffMode[]
  has_phone: boolean
  /** The workspace has a WhatsApp number that can send (the demo one counts). */
  available: boolean
  /** A real Meta connection rather than the demo provider. */
  live: boolean
}

export interface NotificationPrefs {
  alerts: Record<AlertCategory, AlertMode>
  digests: Record<DigestKind, boolean>
  categories: AlertCategory[]
  modes: AlertMode[]
  digest_kinds: DigestKind[]
  email: string
  /** False while the server's mailer only writes to a log: nothing leaves it, and the screen says so. */
  mail_configured: boolean
  whatsapp: WhatsappPrefs
}

export interface WhatsappChanges {
  enabled?: boolean
  alerts?: Partial<Record<StaffCategory, StaffMode>>
  /** null: back to the workspace's window. */
  quiet?: { enabled: boolean; start?: string; end?: string } | null
}

export type WhatsappLogReason = 'quiet_hours' | 'rate_limited' | 'duplicate' | 'no_phone' | 'not_connected' | 'opted_out' | 'provider_error' | 'undeliverable' | 'outside_window'

export interface WhatsappLogRow {
  id: string
  category: StaffCategory
  title: string
  status: 'queued' | 'sent' | 'failed' | 'skipped'
  reason: WhatsappLogReason | null
  provider: string | null
  error: string | null
  at: string | null
  sent_at: string | null
}

export function fetchNotificationPrefs() {
  return apiGet<NotificationPrefs>('/auth/notification-preferences')
}

export function saveNotificationPrefs(changes: { alerts?: Partial<Record<AlertCategory, AlertMode>>; digests?: Partial<Record<DigestKind, boolean>>; whatsapp?: WhatsappChanges }) {
  return apiPatch<NotificationPrefs>('/auth/notification-preferences', changes)
}

export function fetchWhatsappLog() {
  return apiGet<{ log: WhatsappLogRow[] }>('/auth/notification-preferences/whatsapp-log')
}
