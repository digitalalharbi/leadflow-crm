import { apiDelete, apiGet, apiPatch, apiPost } from '@/lib/api'

/**
 * Leads that arrive in a spreadsheet.
 *
 * One source per tab: a spreadsheet with a "Meta" tab and a "TikTok" tab is two
 * sources with two mappings, and treating it as one would stamp both with the
 * same campaign.
 */

export interface SheetHealth {
  state: 'connected' | 'error' | 'never_synced' | 'paused'
  last_synced_at: string | null
  last_error: string | null
  cursor_row: number
}

export interface SheetRun {
  status: 'running' | 'completed' | 'failed'
  trigger: 'scheduled' | 'manual'
  rows_seen: number
  imported: number
  duplicates: number
  failed: number
  failures: Array<{ row: number; reason: string }> | null
  error: string | null
  started_at: string | null
  finished_at: string | null
}

export interface SheetSource {
  id: string
  spreadsheet_id: string
  spreadsheet_name: string | null
  sheet_name: string
  header_row: number
  mapping: Record<string, string>
  defaults: Record<string, unknown> | null
  is_active: boolean
  health: SheetHealth
  last_run: SheetRun | null
}

export interface SheetsPayload {
  sources: SheetSource[]
  connection: {
    connected: boolean
    status: string
    last_error: string | null
    /** False means the platform has no OAuth client — ours to fix, not theirs. */
    oauth_configured: boolean
  }
  mappable: string[]
}

export function fetchSheets() {
  return apiGet<SheetsPayload>('/integrations/sheets')
}

export function fetchSpreadsheets(search?: string) {
  return apiGet<{ spreadsheets: Array<{ id: string; name: string; modified_at: string | null }> }>(
    `/integrations/sheets/spreadsheets${search ? `?search=${encodeURIComponent(search)}` : ''}`,
  )
}

export function fetchTabs(spreadsheetId: string) {
  return apiGet<{ tabs: string[]; name: string | null }>(
    `/integrations/sheets/tabs?spreadsheet_id=${encodeURIComponent(spreadsheetId)}`,
  )
}

/** Reads the sheet and shows what the mapping would produce. Imports nothing. */
export function previewSheet(body: {
  spreadsheet_id: string
  sheet_name: string
  header_row?: number
  mapping?: Record<string, string>
}) {
  return apiPost<{ headers: string[]; rows: string[][]; mapped: Array<Record<string, string | null>> }>(
    '/integrations/sheets/preview',
    body,
  )
}

export function connectSheet(body: {
  spreadsheet_id: string
  spreadsheet_name?: string | null
  sheet_name: string
  header_row?: number
  mapping: Record<string, string>
  defaults?: Record<string, unknown> | null
}) {
  return apiPost<{ source: SheetSource }>('/integrations/sheets', body)
}

export function updateSheet(id: string, body: Partial<{ mapping: Record<string, string>; is_active: boolean; cursor_row: number }>) {
  return apiPatch<{ source: SheetSource }>(`/integrations/sheets/${id}`, body)
}

export function disconnectSheet(id: string) {
  return apiDelete(`/integrations/sheets/${id}`)
}

/** For the moment after a mapping is fixed. The schedule is the normal path. */
export function syncSheet(id: string) {
  return apiPost(`/integrations/sheets/${id}/sync`, {})
}
