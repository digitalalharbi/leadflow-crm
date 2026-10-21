import { apiGet, apiPost } from '@/lib/api'

export type InsightTone = 'bad' | 'warn' | 'good' | 'neutral'

export interface InsightCard {
  key: 'holds_expiring' | 'interested_waiting' | 'visit_gap' | 'no_shows' | 'funnel_leak' | 'best_campaign' | 'wasted_spend' | 'top_partner'
  tone: InsightTone
  values: Record<string, string | number>
  href: string | null
}

export type AnomalyKind = 'lead_spike' | 'lead_drop' | 'cpl_spike' | 'qualified_rate_drop' | 'no_response_spike' | 'tracking_drop' | 'spend_no_qualified' | 'backlog_growth'

export interface Anomaly {
  id: string
  kind: AnomalyKind
  subject: string | null
  severity: 'warning' | 'critical'
  observed: number
  expected: number
  detected_on: string
}

export interface Insights {
  cards: InsightCard[]
  anomalies: Anomaly[]
}

export function fetchInsights() {
  return apiGet<Insights>('/insights')
}

export function dismissAnomaly(id: string) {
  return apiPost<{ dismissed: boolean }>(`/anomalies/${id}/dismiss`)
}
