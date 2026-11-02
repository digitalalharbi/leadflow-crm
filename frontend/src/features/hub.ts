import { apiDelete, apiGet, apiPatch, apiPost } from '@/lib/api'
import type { TrackingLink } from '@/features/tracking'

/* ------------------------------------------------------------------ smart forms */

export const FIELD_TYPES = [
  'text', 'phone', 'email', 'number', 'textarea', 'select', 'multi_select', 'radio', 'checkbox', 'date', 'time', 'hidden',
  'heading', 'paragraph', 'divider', 'consent', 'matrix', 'repeater',
] as const
export type FieldType = (typeof FIELD_TYPES)[number]

/** A condition: `{match: all|any, conditions: [{field, op, value?}]}`. The server and the page run the same vectors. */
export const LOGIC_OPS = ['equals', 'not_equals', 'contains', 'gt', 'gte', 'lt', 'lte', 'empty', 'not_empty'] as const
export type LogicOp = (typeof LOGIC_OPS)[number]
export interface LogicCondition { field: string; op: LogicOp; value?: string | number | boolean | null }
export interface LogicGroup { match: 'all' | 'any'; conditions: LogicCondition[] }

export interface MatrixDef { rows: FieldOption[]; columns: FieldOption[]; multiple: boolean; require_all_rows: boolean; min_answers: number }
export interface RepeaterSubfield { key: string; type: 'text' | 'phone' | 'email' | 'number' | 'select' | 'date' | 'time' | 'textarea'; label: string; required?: boolean; options?: FieldOption[]; placeholder?: string | null }
export interface RepeaterDef { subfields: RepeaterSubfield[]; min_rows: number; max_rows: number; default_rows: number; add_label?: string | null }

export interface LayoutColumn { id: string; fields: string[] }
export interface LayoutRow { id: string; show_when?: LogicGroup | null; hide_when?: LogicGroup | null; columns: LayoutColumn[] }
export interface LayoutSection { id: string; title?: string | null; description?: string | null; step_title?: string | null; show_when?: LogicGroup | null; hide_when?: LogicGroup | null; rows: LayoutRow[] }
export interface FormLayout { sections: LayoutSection[] }

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
  validation?: { min?: number; max?: number; min_length?: number; max_length?: number; pattern?: string; message?: string }
  /** `lead.phone`, `tracking.source`, `custom.x` or `none`. */
  mapping: string
  visible_if?: { field: string; equals?: string } | null
  show_when?: LogicGroup | null
  hide_when?: LogicGroup | null
  require_when?: LogicGroup | null
  matrix?: MatrixDef | null
  repeater?: RepeaterDef | null
}

/* ------------------------------------------------------------------ design and settings (closed lists, never CSS) */

export const HEADER_LAYOUTS = ['minimal', 'centered', 'split', 'cover', 'project_card'] as const
export type HeaderLayout = (typeof HEADER_LAYOUTS)[number]
export const PALETTE = ['emerald', 'teal', 'blue', 'indigo', 'violet', 'rose', 'amber', 'slate', 'stone'] as const
export type PaletteToken = (typeof PALETTE)[number]

export interface FormDesign {
  header: {
    layout: HeaderLayout; show_logo: boolean; logo_size: 'sm' | 'md' | 'lg'; logo_align: 'start' | 'center' | 'end'; show_project_name: boolean
    background: 'none' | 'soft' | 'brand' | 'image'; location: string | null; price_from: number | null; trust_note: string | null
  }
  style: {
    surface: 'card' | 'flat'; radius: 'none' | 'sm' | 'md' | 'lg' | 'xl'; field_spacing: 'tight' | 'normal' | 'loose'; section_spacing: 'tight' | 'normal' | 'loose'
    input_size: 'compact' | 'comfortable' | 'large'; button: 'full' | 'auto'; theme: 'light' | 'brand' | 'neutral'; primary: PaletteToken; accent: PaletteToken
  }
}

export const THANK_ACTIONS = ['whatsapp', 'call', 'appointment', 'project', 'brochure'] as const
export type ThankActionType = (typeof THANK_ACTIONS)[number]
export interface ThankAction { type: ThankActionType; label: string; value: string }
export interface SignalRule { id: string; label: string; points: number; when: LogicGroup }

export interface PublicSettings {
  mode: 'single' | 'multi'
  default_country: string
  thanks: { actions: ThankAction[]; redirect_url: string | null; redirect_delay: number }
  gtm: { events: boolean }
}
export interface FormSettings extends PublicSettings { signals: SignalRule[]; variant: { key: string; group: string | null } }

export const DEFAULT_DESIGN: FormDesign = {
  header: { layout: 'centered', show_logo: true, logo_size: 'md', logo_align: 'center', show_project_name: true, background: 'none', location: null, price_from: null, trust_note: null },
  style: { surface: 'card', radius: 'lg', field_spacing: 'normal', section_spacing: 'normal', input_size: 'comfortable', button: 'full', theme: 'light', primary: 'emerald', accent: 'amber' },
}
export const DEFAULT_SETTINGS: FormSettings = { mode: 'single', default_country: 'SA', thanks: { actions: [], redirect_url: null, redirect_delay: 5 }, gtm: { events: true }, signals: [], variant: { key: 'A', group: null } }

export interface LandingForm {
  id: string
  name: string
  status: 'draft' | 'published' | 'paused' | 'archived'
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
  layout: FormLayout
  design: FormDesign
  settings: FormSettings
  steps: number
  published_version: { id: string; version: number; published_at: string } | null
  has_unpublished_changes: boolean
  links_count: number | null
  slug?: string | null
  page?: LandingPageConfig | null
  public_path?: string | null
}

export type FormInput = Partial<Omit<LandingForm, 'id' | 'project' | 'steps' | 'links_count' | 'published_version' | 'has_unpublished_changes'>> & { project_id?: string | null; template?: string }

export interface FormPresets {
  presets: FormField[]
  types: FieldType[]
  targets: { lead: string[]; tracking: string[] }
}

export const fetchForms = () => apiGet<{ forms: LandingForm[] }>('/tracking/forms').then((r) => r.forms)
export const fetchFormPresets = () => apiGet<FormPresets>('/tracking/forms/presets')
export const checkPageSlug = (slug: string, form?: string) => apiPost<{ slug: string; available: boolean; problem: string | null; suggestion: string | null }>('/tracking/forms/slug', { slug, form })
export const addPageLink = (formId: string, body: { name: string; channel: string; kind?: 'link' | 'qr'; placement?: string; utm_source?: string; utm_medium?: string; utm_campaign?: string; utm_content?: string; utm_term?: string }) =>
  apiPost<{ link: { id: string; token: string; name: string; channel: string; kind: string; path: string } }>(`/tracking/forms/${formId}/links`, body)
export const createForm = (input: FormInput) => apiPost<{ form: LandingForm }>('/tracking/forms', input)
export const updateForm = (id: string, input: FormInput) => apiPatch<{ form: LandingForm }>(`/tracking/forms/${id}`, input)
export const deleteForm = (id: string) => apiDelete(`/tracking/forms/${id}`)

/* ------------------------------------------------------------------ the public landing page */

export interface LandingTag {
  kind: string
  id: string
  /** What a form submission is called on this platform, when the workspace mapped it. The server sends the same name under the same id. */
  submit_event?: string | null
}

export type PageTemplate = 'real_estate_lead' | 'project_showcase' | 'minimal_lead'
export type PageCtaKind = 'form' | 'whatsapp' | 'phone'
export type PageFactKey = 'location' | 'project_type' | 'price_from' | 'unit_types' | 'delivery'

/** What makes a form a campaign page: a closed list, so a manager chooses within a page that stays good. */
export interface LandingPageConfig {
  template: PageTemplate
  developer_name: string | null
  developer_logo_url: string | null
  facts: { key: PageFactKey; value: string; visible?: boolean }[]
  cta: {
    form: { enabled: boolean }
    whatsapp: { enabled: boolean; number: string | null; message: string | null; mode?: 'track' | 'inbound' }
    phone: { enabled: boolean; number: string | null }
    primary: PageCtaKind
    secondary: PageCtaKind | null
    floating_whatsapp: boolean
    floating_call: boolean
  }
  auto_steps: boolean
  seo: { title: string | null; description: string | null; og_image: string | null; index: boolean }
  tags?: string[]
  mirror?: { enabled: boolean; spreadsheet_id: string | null; tab: string | null }
}

export interface PublicLanding {
  landing: {
    page?: LandingPageConfig | null
    token: string
    version: number
    headline: string | null
    description: string | null
    benefits: string[]
    cta_label: string | null
    logo_url: string | null
    image_url: string | null
    thank_you: { title: string | null; message: string | null }
    project: { name: string; logo_url?: string | null } | null
    fields: FormField[]
    layout: FormLayout
    design: FormDesign
    settings: PublicSettings
    steps: number
    consent: { required: boolean; text: string | null; privacy_url: string | null }
    tags: LandingTag[]
  }
  tracking: { visitor_id: string; session_id: string; view_event_id: string | null; ref?: string; datalayer: Record<string, string> }
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

export interface LandingSubmitResult {
  ok: boolean
  duplicate: boolean
  event_id: string
  thank_you: { title: string | null; message: string | null; actions: ThankAction[]; redirect_url: string | null; redirect_delay: number }
  /** Ids and campaign names only: never a customer's name, number or address. */
  datalayer: Record<string, string | number>
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
  /** Mohtam's event → the platform's, when the workspace says so. */
  event_map?: Record<string, string> | null
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
  event_map?: Record<string, string>
  events?: string[]
  send_value?: boolean
  hash_identifiers?: boolean
  consent_mode?: 'required' | 'unless_denied'
}

export const fetchDestinationCatalogue = () => apiGet<{ kinds: DestinationKindInfo[]; events: string[]; default_events: string[]; platform_events?: string[] }>('/tracking/destinations/catalogue')
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

/* ------------------------------------------------------------------ the builder's own calls */

export interface FormTemplateInfo { key: string; name: string; description: string; fields: number; steps: number }
export interface FormVersionInfo { id: string; version: number; variant: string; published_at: string; published_by: string | null; is_live: boolean; submissions: number }

export const fetchFormTemplates = () => apiGet<{ templates: FormTemplateInfo[] }>('/tracking/forms/templates').then((r) => r.templates)
export const publishForm = (id: string) => apiPost<{ form: LandingForm }>(`/tracking/forms/${id}/publish`, {})
export const duplicateForm = (id: string) => apiPost<{ form: LandingForm }>(`/tracking/forms/${id}/duplicate`, {})
export const fetchFormVersions = (id: string) => apiGet<{ versions: FormVersionInfo[] }>(`/tracking/forms/${id}/versions`).then((r) => r.versions)
export const fetchForm = (id: string) => apiGet<{ form: LandingForm }>(`/tracking/forms/${id}`).then((r) => r.form)

export async function uploadFormAsset(file: File): Promise<{ url: string; width: number; height: number }> {
  const { http } = await import('@/lib/api')
  const body = new FormData()
  body.append('file', file)
  const response = await http.post<{ data: { url: string; width: number; height: number } }>('/tracking/forms/assets', body, { headers: { 'Content-Type': 'multipart/form-data' } })

  return response.data.data
}

export interface FormAnalyticsData {
  page?: {
    unique_visitors: number
    whatsapp_clicks: number
    call_clicks: number
    leads: number
    qualified: number
    appointments: number
    visits: number
    reservations: number
    sales: number
    revenue_minor: number
    funnel: { key: string; n: number; rate: number | null }[]
    cost: { spend_minor: number | null; cpl: number | null; cpql: number | null; per_appointment: number | null; per_reservation: number | null; cpa: number | null; roas: number | null }
  } | null
  form: { id: string; name: string }
  range: { from: string; to: string; days: number }
  totals: { views: number; starts: number; submits: number; completion_rate: number | null; abandonment_rate: number | null; avg_completion_seconds: number | null }
  steps: { step: number; entered: number; completed: number; drop_off: number | null }[]
  abandoned_at: { field: string | null; step: number | null; count: number }[]
  matrix: { field: string; label: string; columns: FieldOption[]; rows: { row: string; label: string; counts: Record<string, number>; answered: number }[] }[]
  versions: { version: number; variant: string; submits: number; is_live: boolean }[]
}

export const fetchFormAnalytics = (id: string, days: number) => apiGet<FormAnalyticsData>(`/tracking/forms/${id}/analytics`, { days })
