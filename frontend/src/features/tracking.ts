import { apiDelete, apiGet, apiPatch, apiPost } from '@/lib/api'

export const CHANNELS = ['meta', 'tiktok', 'snapchat', 'google', 'x', 'linkedin', 'whatsapp', 'landing_page', 'portal', 'email', 'sms', 'influencer', 'broker', 'qr', 'other'] as const
export type Channel = (typeof CHANNELS)[number]

export interface LinkStats {
  clicks: number
  visitors: number
  visits: number
  forms: number
  whatsapp_clicks: number
  call_clicks: number
  leads: number
  qualified: number
  won: number
  revenue: number
  conversion: number | null
  last_event_at: string | null
  /** Of the people this link brought: how many were interested, saw a unit, held one. Absent on an older server. */
  interested?: number
  visits_done?: number
  reservations?: number
}

export interface TrackingLink {
  id: string
  token: string
  url: string
  name: string
  kind: 'link' | 'qr'
  channel: Channel
  project?: { id: string; name: string } | null
  property?: { id: string; code: string } | null
  campaign?: { id: string; name: string } | null
  ad?: { id: string; name: string } | null
  creative?: { id: string; name: string } | null
  rep?: { id: string; name: string } | null
  partner?: { id: string; name: string; type: string } | null
  /** What a QR code is printed on. */
  placement?: Placement | null
  landing_page: string | null
  branch: string | null
  destination_url: string | null
  utm: Partial<Record<'source' | 'medium' | 'campaign' | 'term' | 'content', string>>
  is_active: boolean
  is_live: boolean
  expires_at: string | null
  stats: LinkStats
}

export const PLACEMENTS = ['project', 'property', 'event', 'billboard', 'rep', 'broker', 'other'] as const
export type Placement = (typeof PLACEMENTS)[number]

export interface LinkInput {
  name: string
  kind?: 'link' | 'qr'
  channel?: Channel
  partner_id?: string | null
  placement?: Placement | null
  project_id?: string | null
  campaign_id?: string | null
  destination_url?: string | null
  landing_page?: string | null
  branch?: string | null
  utm_source?: string | null
  utm_medium?: string | null
  utm_campaign?: string | null
  is_active?: boolean
}

export interface LinkDetail {
  link: TrackingLink
  recent: { type: string; at: string; device: string | null; browser: string | null; referrer: string | null; lead_id: string | null }[]
  embed: { link: string; script: string }
}

export const MODELS = ['first', 'last', 'linear', 'position'] as const
export type AttributionModel = (typeof MODELS)[number]
export const DIMENSIONS = ['channel', 'link', 'campaign', 'ad', 'creative', 'project', 'rep', 'partner', 'landing_page', 'branch', 'source'] as const
export type Dimension = (typeof DIMENSIONS)[number]

export interface AttributionRow {
  key: string
  label: string | null
  clicks: number
  leads: number
  qualified: number
  interested: number
  appointments: number
  visits: number
  reservations: number
  won: number
  revenue: number
  conversion: number | null
}

export interface AttributionData {
  model: AttributionModel
  dimension: Dimension
  from: string
  to: string
  days: number
  totals: { leads: number; unattributed: number; unattributed_percent: number | null; qualified: number; interested: number; appointments: number; visits: number; reservations: number; won: number; revenue: number }
  rows: AttributionRow[]
}

export const fetchLinks = (days?: number) => apiGet<{ links: TrackingLink[] }>('/tracking/links', days ? { days } : undefined)
export const fetchLink = (id: string) => apiGet<LinkDetail>(`/tracking/links/${id}`)
export const createLink = (input: LinkInput) => apiPost<{ link: TrackingLink }>('/tracking/links', input)
export const updateLink = (id: string, input: Partial<LinkInput>) => apiPatch<{ link: TrackingLink }>(`/tracking/links/${id}`, input)
export const deleteLink = (id: string) => apiDelete(`/tracking/links/${id}`)
export const fetchAttribution = (model: AttributionModel, dimension: Dimension, days: number, projectId?: string) =>
  apiGet<AttributionData>('/tracking/attribution', { model, dimension, days, project_id: projectId })

export type HealthStatus = 'healthy' | 'attention' | 'risk' | 'idle'

export interface PlatformHealth {
  platform: 'meta' | 'tiktok' | 'snapchat' | 'google_ads' | 'x' | 'linkedin' | 'whatsapp' | 'landing_page'
  status: HealthStatus
  connected: boolean
  receiving: boolean
  events: number
  accepted: number
  last_event_at: string | null
  success_rate: number | null
  rejected: number
  duplicates: number
  duplicate_rate: number | null
  leads: number
  missing_utm: number
  missing_utm_percent: number | null
  unattributed: number
  unattributed_percent: number | null
  offline: {
    state: string
    configured: boolean
    missing: string[]
    sent: number
    failed: number
    last_sent_at: string | null
    last_error: string | null
    won_last_sent_at: string | null
    won_waiting: number
  } | null
  trend: number[]
}

export interface HealthRecommendation {
  key: 'won_not_sent' | 'platform_silent' | 'rejecting' | 'duplicates' | 'unattributed' | 'links_without_utm' | 'leads_without_utm' | 'no_links'
  severity: 'critical' | 'warning'
  platform: PlatformHealth['platform'] | null
  params: Record<string, number>
  to: 'health' | 'attribution' | 'links'
}

export interface TrackingHealthData {
  days: number
  summary: { connected: number; receiving: number; at_risk: number; platforms: number; unattributed_percent: number | null; missing_utm_percent: number | null; links_without_utm_percent: number | null }
  platforms: PlatformHealth[]
  recommendations: HealthRecommendation[]
}

export const fetchTrackingHealth = (days: number) => apiGet<TrackingHealthData>('/tracking/health', { days })

/** The link as a QR code (SVG). Fetched with the session, so it is a blob rather than an <img src>. */
export async function fetchLinkQr(id: string): Promise<string> {
  const { http } = await import('@/lib/api')
  const response = await http.get(`/tracking/links/${id}/qr`, { responseType: 'text', transformResponse: (data) => data })

  return response.data as string
}

export type QrFormat = 'svg' | 'png'

/** The code as a file the person saves: fetched with their session, then handed to the browser. */
export async function downloadLinkQr(id: string, format: QrFormat, name: string): Promise<void> {
  const { http } = await import('@/lib/api')
  const response = await http.get(`/tracking/links/${id}/qr`, { params: { format, size: format === 'png' ? 1024 : undefined, download: 1 }, responseType: 'blob' })
  const url = URL.createObjectURL(response.data as Blob)
  const anchor = document.createElement('a')

  anchor.href = url
  anchor.download = `qr-${name.replace(/[^\p{L}\p{N}]+/gu, '-').replace(/^-|-$/g, '') || 'link'}.${format}`
  anchor.click()
  URL.revokeObjectURL(url)
}

export const PRINT_SIZES = ['sticker', 'flyer', 'poster'] as const
export type PrintSize = (typeof PRINT_SIZES)[number]

const PAGE: Record<PrintSize, { page: string; qr: string; title: string }> = {
  sticker: { page: 'A6', qr: '70mm', title: '16pt' },
  flyer: { page: 'A5', qr: '110mm', title: '22pt' },
  poster: { page: 'A4', qr: '150mm', title: '30pt' },
}

const escapeHtml = (text: string) => text.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] as string)

/** A page ready for the printer: the code at a size that scans from where it hangs, the name, and the short link under it. */
export function printableQr(svg: string, opts: { title: string; subtitle?: string; url: string; size: PrintSize; dir: 'rtl' | 'ltr' }): string {
  const p = PAGE[opts.size]

  return `<!doctype html><html dir="${opts.dir}"><head><meta charset="utf-8"><title>${escapeHtml(opts.title)}</title><style>
@page { size: ${p.page}; margin: 10mm }
html, body { margin: 0; height: 100% }
body { font-family: system-ui, sans-serif; display: grid; place-items: center; text-align: center }
main { display: grid; gap: 6mm; justify-items: center }
h1 { margin: 0; font-size: ${p.title} }
p { margin: 0; color: #444; font-size: 11pt }
.qr svg { width: ${p.qr}; height: ${p.qr} }
.url { direction: ltr; font-family: ui-monospace, monospace; font-size: 10pt }
</style></head><body><main><h1>${escapeHtml(opts.title)}</h1>${opts.subtitle ? `<p>${escapeHtml(opts.subtitle)}</p>` : ''}<div class="qr">${svg}</div><p class="url">${escapeHtml(opts.url)}</p></main></body></html>`
}
