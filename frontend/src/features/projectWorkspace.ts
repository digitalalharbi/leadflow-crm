import { apiGet } from '@/lib/api'
import type { DashboardData } from '@/types/api'

/**
 * Everything a project's workspace asks that no screen asked of a single
 * project before.
 *
 * Deliberately thin: each of these is an endpoint that already existed and
 * already took `project_id`, called with it set. The workspace is a different
 * way of looking at what the product knows, not a second copy of it.
 */

export interface ReportRow {
  key: string
  label: string
  total: number
  qualified: number
  appointments: number
  won: number
  lost: number
  conversion_rate: number
  share: number
}

export interface ProjectActivity {
  id: number
  type: string
  description: string | null
  at: string
  lead: { id: string; name: string } | null
  by: string | null
  /** A rule or the telephony did this, rather than a person. */
  automated: boolean
}

export function fetchProjectSummary(projectId: string) {
  return apiGet<DashboardData>('/dashboard', { project_id: projectId })
}

export function fetchProjectReport(projectId: string, type: 'campaign' | 'platform') {
  return apiGet<{ rows: ReportRow[]; totals: Omit<ReportRow, 'key' | 'label' | 'share'> }>('/reports', {
    type,
    project_id: projectId,
  })
}

export function fetchProjectActivity(projectId: string) {
  return apiGet<{ activities: ProjectActivity[] }>(`/projects/${projectId}/activity`).then((r) => r.activities)
}
