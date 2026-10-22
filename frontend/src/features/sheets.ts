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
  /** Fields a person chose by hand; the guess never overwrites these. */
  overrides: Record<string, string | null>
  /** How sure the automatic mapping is, per field, 0–1. */
  confidence: Record<string, number>
  /** Fields with two equally likely columns — the only choice left to a person. */
  ambiguous: MappingChoice[]
  /** Columns that are not a known field and are kept as custom fields. */
  custom_columns: string[]
  headers: string[]
  /** What the sheet's Apps Script trigger calls when a row is added. */
  ping_url: string
  last_push_at: string | null
  defaults: Record<string, unknown> | null
  is_active: boolean
  health: SheetHealth
  last_run: SheetRun | null
}

export interface MappingChoice {
  field: string
  candidates: string[]
}

/** The mapping worked out from the header row and a few real rows. */
export interface MappingGuess {
  mapping: Record<string, string>
  confidence: Record<string, number>
  ambiguous: MappingChoice[]
  custom: Record<string, string>
  usable: boolean
}

export interface SheetsPayload {
  sources: SheetSource[]
  connection: {
    connected: boolean
    status: string
    last_error: string | null
    /** False means the platform has no OAuth client — ours to fix, not theirs. */
    oauth_configured: boolean
    /** A file can be chosen — the picker's key and project number are set. */
    picker_configured: boolean
  }
  mappable: string[]
}

export function fetchSheets() {
  return apiGet<SheetsPayload>('/integrations/sheets')
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
  return apiPost<{ headers: string[]; rows: string[][]; mapped: Array<Record<string, string | null>>; guess: MappingGuess }>(
    '/integrations/sheets/preview',
    body,
  )
}

export function connectSheet(body: {
  spreadsheet_id: string
  spreadsheet_name?: string | null
  sheet_name: string
  header_row?: number
  /** Only what a person changed; the rest is worked out on the server. */
  mapping?: Record<string, string>
  defaults?: Record<string, unknown> | null
}) {
  return apiPost<{ source: SheetSource }>('/integrations/sheets', body)
}

/** A person's choice for one or more fields, kept through every later re-map. */
export function remapSheet(id: string, overrides: Record<string, string | null>) {
  return apiPatch<{ source: SheetSource }>(`/integrations/sheets/${id}/mapping`, { overrides })
}

/** The few lines a sheet needs so a new row is synced in seconds. */
export function appsScriptFor(pingUrl: string): string {
  return [
    'function onLeadRowAdded(e) {',
    `  UrlFetchApp.fetch('${pingUrl}', { method: 'post', muteHttpExceptions: true });`,
    '}',
    '// Triggers → Add trigger → onLeadRowAdded → From spreadsheet → On change',
  ].join('\n')
}

export type ConfidenceLevel = 'certain' | 'likely' | 'check'

export function confidenceLevel(value: number | undefined): ConfidenceLevel {
  if (value === undefined) return 'check'
  if (value >= 0.95) return 'certain'

  return value >= 0.75 ? 'likely' : 'check'
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

/* ------------------------------------------------------------ the grant */

/**
 * Starts the Google consent flow.
 *
 * Returns a URL rather than redirecting: the state that proves the person came
 * back is minted by the API against their session, and a link the browser
 * builds for itself could not carry one.
 */
export function startGoogleAuthorization() {
  return apiPost<{ url: string }>('/integrations/google/authorize')
}

export function disconnectGoogle() {
  return apiPost('/integrations/google/disconnect')
}

/**
 * What the browser needs to open Google's file picker.
 *
 * Fetched when the person asks to choose a file, not with the page: the access
 * token in it is short-lived and there is no reason for it to exist in the
 * browser for the many visits that never open the picker.
 */
export function fetchPickerCredentials() {
  return apiPost<{ access_token: string; developer_key: string; app_id: string }>(
    '/integrations/google/picker',
  )
}
