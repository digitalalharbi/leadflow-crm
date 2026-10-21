import { apiGet, apiPatch } from '@/lib/api'

export type AlertCategory = 'hot_lead' | 'visit_requested' | 'reservation_intent' | 'sla_breach' | 'automation_failure' | 'high_value' | 'tracking_failure' | 'budget_anomaly'
export type AlertMode = 'instant' | 'daily' | 'weekly' | 'off'
export type DigestKind = 'daily_sales' | 'weekly_marketing' | 'weekly_executive'

export interface NotificationPrefs {
  alerts: Record<AlertCategory, AlertMode>
  digests: Record<DigestKind, boolean>
  categories: AlertCategory[]
  modes: AlertMode[]
  digest_kinds: DigestKind[]
  email: string
  /** False while the server's mailer only writes to a log: nothing leaves it, and the screen says so. */
  mail_configured: boolean
}

export function fetchNotificationPrefs() {
  return apiGet<NotificationPrefs>('/auth/notification-preferences')
}

export function saveNotificationPrefs(changes: { alerts?: Partial<Record<AlertCategory, AlertMode>>; digests?: Partial<Record<DigestKind, boolean>> }) {
  return apiPatch<NotificationPrefs>('/auth/notification-preferences', changes)
}
