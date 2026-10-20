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
  partner_id: number | null
  landing_page: string | null
  branch: string | null
  destination_url: string | null
  utm: Partial<Record<'source' | 'medium' | 'campaign' | 'term' | 'content', string>>
  is_active: boolean
  is_live: boolean
  expires_at: string | null
  stats: LinkStats
}

export interface LinkInput {
  name: string
  kind?: 'link' | 'qr'
  channel?: Channel
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
