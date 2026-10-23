import { apiDelete, apiGet, apiPatch, apiPost } from '@/lib/api'
import type { TrackingLink } from '@/features/tracking'

/* ------------------------------------------------------------------ smart forms */

export const FIELD_TYPES = ['text', 'phone', 'email', 'number', 'select', 'multi_select', 'radio', 'checkbox', 'date', 'textarea', 'hidden'] as const
export type FieldType = (typeof FIELD_TYPES)[number]

export interface FieldOption { value: string; label: string }

export interface FormField {
  key: string
  type: FieldType
  label: string
  placeholder?: string | null
  help?: string | null
  required: boolean
  hidden: boolean
  default?: string | null
  options?: FieldOption[]
  step?: number
  order?: number
  validation?: Record<string, string | number>
  /** `lead.phone`, `tracking.source`, `custom.x` or `none`. */
  mapping: string
  visible_if?: { field: string; equals?: string } | null
}

export interface LandingForm {
  id: string
  name: string
  status: 'draft' | 'published' | 'archived'
  project: { id: string; name: string } | null
  headline: string | null
  description: string | null
  benefits: string[]
  cta_label: string | null
  logo_url: string | null
  image_url: string | null
  thank_you_title: string | null
  thank_you_message: string | null
  consent_required: boolean
  consent_text: string | null
  privacy_url: string | null
  fields: FormField[]
  steps: number
  links_count: number | null
}

export type FormInput = Partial<Omit<LandingForm, 'id' | 'project' | 'steps' | 'links_count'>> & { project_id?: string | null }

export interface FormPresets {
  presets: FormField[]
  types: FieldType[]
  targets: { lead: string[]; tracking: string[] }
}

export const fetchForms = () => apiGet<{ forms: LandingForm[] }>('/tracking/forms').then((r) => r.forms)
export const fetchFormPresets = () => apiGet<FormPresets>('/tracking/forms/presets')
export const createForm = (input: FormInput) => apiPost<{ form: LandingForm }>('/tracking/forms', input)
export const updateForm = (id: string, input: FormInput) => apiPatch<{ form: LandingForm }>(`/tracking/forms/${id}`, input)
export const deleteForm = (id: string) => apiDelete(`/tracking/forms/${id}`)

/** Is this field shown, given what has been answered so far? One rule, shared by the builder preview and the public page. */
export function isVisible(field: FormField, answers: Record<string, unknown>): boolean {
  if (field.hidden || field.type === 'hidden') return false
  const c = field.visible_if
  if (!c) return true
  const given = answers[c.field]
  const text = Array.isArray(given) ? given.join(',') : String(given ?? '')
  if (c.equals === undefined || c.equals === '') return text !== ''
  return Array.isArray(given) ? given.includes(c.equals) : text === c.equals
}

/* ------------------------------------------------------------------ the public landing page */

export interface LandingTag { kind: string; id: string }

export interface PublicLanding {
  landing: {
    token: string
    headline: string | null
    description: string | null
    benefits: string[]
    cta_label: string | null
    logo_url: string | null
    image_url: string | null
    thank_you: { title: string | null; message: string | null }
    project: { name: string } | null
    fields: FormField[]
    steps: number
    consent: { required: boolean; text: string | null; privacy_url: string | null }
    tags: LandingTag[]
  }
  tracking: { visitor_id: string; session_id: string; view_event_id: string | null; datalayer: Record<string, string> }
}

export async function fetchLanding(token: string, query: Record<string, string>): Promise<PublicLanding> {
  const { http } = await import('@/lib/api')
  const response = await http.get<{ data: PublicLanding }>(`/public/landing/${token}`, { params: query })

  return response.data.data
}

export interface LandingSubmitInput {
  submission_id: string
  answers: Record<string, unknown>
  consent: boolean
  website?: string
  visitor_id?: string
  session_id?: string
  url?: string
  referrer?: string
  click_ids?: Record<string, string>
  utm_source?: string
  utm_medium?: string
  utm_campaign?: string
  utm_term?: string
  utm_content?: string
}

export async function submitLanding(token: string, input: LandingSubmitInput): Promise<void> {
  const { http } = await import('@/lib/api')
  await http.post(`/public/landing/${token}/submit`, input)
}

/* ------------------------------------------------------------------ link analytics */

export const FUNNEL_STEPS = ['click', 'view', 'form_start', 'submit', 'qualified', 'interested', 'visit', 'reservation', 'won'] as const
export type FunnelStep = (typeof FUNNEL_STEPS)[number]

export interface FunnelRow { key: FunnelStep; count: number; drop_off: number | null; of_start: number | null }

export type Health = 'healthy' | 'warning' | 'critical'
export interface HealthCheck { key: string; state: 'ok' | 'warning' | 'critical'; [more: string]: unknown }

export interface LinkAnalyticsData {
  link: TrackingLink
  range: { from: string; to: string; days: number }
  analytics: {
    counts: Record<string, number | null>
    rates: Record<string, number | null>
    funnel: FunnelRow[]
    trend: { date: string; clicks: number; views: number; submissions: number; leads: number; won: number }[]
  }
  health: { status: Health; checks: HealthCheck[] }
  recent: { event: string; at: string; device: string | null; lead_id: string | null }[]
}

export const fetchLinkAnalytics = (id: string, days: number) => apiGet<LinkAnalyticsData>(`/tracking/links/${id}/analytics`, { days })

/* ------------------------------------------------------------------ project acquisition */

export interface AcquisitionData {
  project: { id: string; name: string }
  range: { from: string; to: string; days: number }
  links: { id: string; name: string; channel: string; mode: string; token: string; url: string; is_live: boolean; health: Health | null; stats: Record<string, number | null> }[]
  forms: { id: string; name: string; status: string; links_count: number; steps: number; submissions: number }[]
  funnel: FunnelRow[]
  sources: { channels: { channel: string; links: number; clicks: number; leads: number; qualified: number; won: number; revenue: number | null }[]; unattributed: number }
  campaigns: { id: string; name: string; platform: string | null; leads: number; qualified: number; interested: number; reservations: number; won: number; spend: number | null; revenue: number | null }[]
  events: { event: string; at: string; link: string | null; device: string | null; lead_id: string | null }[]
  attribution: { first: unknown; last: unknown }
  diagnostics: {
    links: Record<Health, number>
    issues: { link: string; link_id: string; check: string; state: 'warning' | 'critical' }[]
    destinations: { id: string; kind: string; name: string; is_enabled: boolean; readiness: DestinationReadiness; last_error: string | null }[]
  }
}

export const fetchAcquisition = (projectId: string, days: number) => apiGet<AcquisitionData>(`/projects/${projectId}/acquisition`, { days })

/* ------------------------------------------------------------------ destinations */

export type DestinationReadiness = 'not_configured' | 'awaiting_credentials' | 'browser_only' | 'ready' | 'disabled' | string

export interface DestinationKindInfo {
  kind: string
  label: string
  browser: boolean
  server: boolean
  config: string[]
  credentials: string[]
  conversion_platform: string | null
}

export interface Destination {
  id: string
  kind: string
  label: string
  name: string
  project: { id: string; name: string } | null
  is_enabled: boolean
  config: Record<string, string>
  credentials_set: string[]
  events: string[]
  send_value: boolean
  hash_identifiers: boolean
  consent_mode: 'required' | 'unless_denied'
  browser: boolean
  server: boolean
  readiness: DestinationReadiness
  last_event_at: string | null
  last_error: string | null
  deliveries_7d: Record<string, number>
}

export interface DestinationInput {
  kind?: string
  name?: string
  project_id?: string | null
  is_enabled?: boolean
  config?: Record<string, string>
  credentials?: Record<string, string>
  events?: string[]
  send_value?: boolean
  hash_identifiers?: boolean
  consent_mode?: 'required' | 'unless_denied'
}

export const fetchDestinationCatalogue = () => apiGet<{ kinds: DestinationKindInfo[]; events: string[]; default_events: string[] }>('/tracking/destinations/catalogue')
export const fetchDestinations = () => apiGet<{ destinations: Destination[] }>('/tracking/destinations').then((r) => r.destinations)
export const createDestination = (input: DestinationInput) => apiPost<{ destination: Destination }>('/tracking/destinations', input)
export const updateDestination = (id: string, input: DestinationInput) => apiPatch<{ destination: Destination }>(`/tracking/destinations/${id}`, input)
export const deleteDestination = (id: string) => apiDelete(`/tracking/destinations/${id}`)

/* ------------------------------------------------------------------ the link builder */

export const LINK_MODES = ['redirect', 'landing'] as const
export const UTM_KEYS = ['source', 'medium', 'campaign', 'term', 'content'] as const

/** The UTMs a platform expects, so a marketer is not asked to remember them. */
export const UTM_DEFAULTS: Record<string, { source: string; medium: string }> = {
  meta: { source: 'facebook', medium: 'paid_social' },
  tiktok: { source: 'tiktok', medium: 'paid_social' },
  snapchat: { source: 'snapchat', medium: 'paid_social' },
  google: { source: 'google', medium: 'cpc' },
  x: { source: 'x', medium: 'paid_social' },
  linkedin: { source: 'linkedin', medium: 'paid_social' },
  whatsapp: { source: 'whatsapp', medium: 'message' },
  email: { source: 'email', medium: 'email' },
  sms: { source: 'sms', medium: 'sms' },
  influencer: { source: 'influencer', medium: 'social' },
  broker: { source: 'broker', medium: 'referral' },
  qr: { source: 'qr', medium: 'offline' },
}

/* ------------------------------------------------------------------ helpers the builder and its tests share */

/** The address with the parameters appended — each encoded, none empty, none duplicated. */
export function withParams(url: string, params: Record<string, string | null | undefined>): string {
  const [base, hash] = url.split('#')
  const [path, query = ''] = base.split('?')
  const search = new URLSearchParams(query)

  for (const [key, value] of Object.entries(params)) {
    if (value !== null && value !== undefined && value !== '') search.set(key, value)
  }

  const q = search.toString()

  return `${path}${q ? `?${q}` : ''}${hash ? `#${hash}` : ''}`
}

/** A campaign name as a UTM value: lower case, words joined by underscores, nothing a marketer would have to escape. */
export function utmSlug(text: string): string {
  return text.trim().toLowerCase().replace(/[^\p{L}\p{N}]+/gu, '_').replace(/^_+|_+$/g, '')
}

/** A custom parameter's name must be one a URL and a report can carry: a word, no spaces. */
export const validParamName = (name: string): boolean => /^[a-z][a-z0-9_]{0,31}$/i.test(name)
