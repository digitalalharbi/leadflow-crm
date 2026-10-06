import { apiGet } from '@/lib/api'
import type { DriverRow } from '@/components/charts'

/**
 * Whether the things the product measures actually predict a sale.
 *
 * `matured_before` is the date the cohort was cut at. Leads newer than that are
 * excluded: one that arrived this morning has not been won yet and probably has
 * not been lost either, and counting it drags every recent band towards zero —
 * which makes "we got slower" and "we got newer" look identical.
 */
export interface DriverAnalysis {
  matured_before: string
}

export interface SpeedAnalysis extends DriverAnalysis {
  buckets: (DriverRow & { bucket: string })[]
  /** Rows whose first contact precedes the lead arriving — a data defect. */
  excluded: number
}

export interface ScoreAnalysis extends DriverAnalysis {
  bands: (DriverRow & { band: string })[]
}

export interface Drivers {
  speed: SpeedAnalysis
  score: ScoreAnalysis
}

export function fetchDrivers(params: Record<string, unknown> = {}) {
  return apiGet<Drivers>('/reports/drivers', params)
}
