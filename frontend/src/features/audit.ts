import { apiGet } from '@/lib/api'

export const AUDIT_CATEGORIES = ['assignment', 'status', 'reservation', 'visit', 'deal', 'automation', 'reports', 'permissions', 'campaigns', 'other'] as const
export type AuditCategory = (typeof AUDIT_CATEGORIES)[number]

export interface AuditEntry {
  id: string
  at: string
  category: AuditCategory
  action: string
  actor: string | null
  subject: string | null
  before: string | null
  after: string | null
  /** A person pressed it, a rule or the journey did, or a sweep did. */
  source: 'user' | 'automation' | 'system'
  lead_id: string | null
}

export function fetchAudit(category: AuditCategory | '', days: number) {
  return apiGet<{ entries: AuditEntry[]; categories: AuditCategory[] }>('/audit', { ...(category ? { category } : {}), days })
}
