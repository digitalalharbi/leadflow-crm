import { apiGet, apiPatch } from '@/lib/api'

export const ALERT_KINDS = ['lead_spike', 'lead_drop', 'cpl_spike', 'qualified_rate_drop', 'no_response_spike', 'tracking_drop', 'backlog_growth', 'spend_no_qualified', 'manual_spend_counts', 'manual_spend_stale'] as const
export type AlertKind = (typeof ALERT_KINDS)[number]

export type Source = 'project' | 'workspace' | 'default'

export interface AlertSetting {
  kind: AlertKind
  enabled: boolean
  enabled_source: Source
  /** Null for an alert that is only on or off. */
  threshold: { value: number; source: Source; workspace: number; default: number; min: number; max: number; unit: 'percent' | 'money' | 'days' } | null
}

export interface AnomalySettingsData {
  alerts: AlertSetting[]
  project: { id: string; name: string } | null
}

export const fetchAnomalySettings = (projectId?: string) => apiGet<AnomalySettingsData>('/anomaly-settings', projectId ? { project_id: projectId } : undefined)

/** A null clears the setting: the workspace's value for a project, the built-in line for the workspace. */
export const saveAnomalySettings = (alerts: Partial<Record<AlertKind, { enabled?: boolean | null; value?: number | null }>>, projectId?: string) =>
  apiPatch<AnomalySettingsData>('/anomaly-settings', { alerts, project_id: projectId })
