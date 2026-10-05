import { apiGet } from '@/lib/api'

export interface QualityCheck {
  key: string
  count: number
  /** The leads-list query that opens exactly the rows this number counted. */
  filter: Record<string, string>
}

export interface IntegrationHealth {
  id: string
  provider: string
  name: string
  status: string
  healthy: boolean
  last_synced_at: string | null
  last_error: string | null
}

export interface DataQualityReport {
  checks: QualityCheck[]
  total_issues: number
  integrations: IntegrationHealth[]
  checked_at: string
}

export function fetchDataQuality() {
  return apiGet<DataQualityReport>('/data-quality')
}
