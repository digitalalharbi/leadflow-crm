import { apiGet } from '@/lib/api'
import type { IngestionDay, IngestionSource } from '@/components/charts'

/**
 * Evidence that leads are still arriving.
 *
 * `duplicate_rate` is null rather than 0 when nothing arrived at all: "0%
 * duplicates" on a dead integration reads as perfect health, which is the
 * opposite of what it means.
 */
export interface IngestionSummary {
  total: number
  accepted: number
  duplicates: number
  duplicate_rate: number | null
}

export interface IngestionInsights {
  summary: IngestionSummary
  daily: IngestionDay[]
  by_provider: IngestionSource[]
}

export function fetchIngestion(days = 30) {
  return apiGet<IngestionInsights>(`/integrations/ingestion?days=${days}`)
}
