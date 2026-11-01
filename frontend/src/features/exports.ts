import { apiGet, apiPost } from '@/lib/api'

export type ExportKind = 'leads' | 'company'
export type ExportFormat = 'csv' | 'xlsx' | 'zip'
export type ExportStatus = 'queued' | 'running' | 'ready' | 'failed' | 'expired'

export interface DataExport {
  id: string
  kind: ExportKind
  format: ExportFormat
  status: ExportStatus
  rows: number
  bytes: number
  filename: string | null
  error: string | null
  created_at: string | null
  finished_at: string | null
  expires_at: string | null
}

export function fetchExports() {
  return apiGet<{ exports: DataExport[] }>('/exports')
}

export function startExport(body: { kind: ExportKind; format: ExportFormat; filters?: Record<string, unknown> }) {
  return apiPost<{ export: DataExport }>('/exports', body)
}

/** A five-minute signed link; opened straight away so it never sits anywhere. */
export async function openExport(id: string) {
  const { url } = await apiPost<{ url: string }>(`/exports/${id}/link`)
  window.location.assign(url)
}

export const isPending = (e: DataExport) => e.status === 'queued' || e.status === 'running'
