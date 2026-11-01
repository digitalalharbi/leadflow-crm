import { apiDelete, apiGet, apiPatch, apiPost } from '@/lib/api'

export type SourceType = 'landing_page' | 'platform_form' | 'google_sheet' | 'whatsapp' | 'call' | 'api' | 'import'
export type SourceStatus = 'connected' | 'awaiting_setup' | 'error'

export interface LeadSource {
  id: string
  type: SourceType
  name: string
  status: SourceStatus
  leads: number
  qualified: number
  appointments: number
  sales: number
  conversion_rate: number | null
  last_lead_at: string | null
  tracking_health: 'receiving' | 'quiet' | 'not_applicable'
  state?: string
  path?: string | null
  provider?: string
  clicks?: number
}

export interface LeadFormMapping {
  id: string
  provider: string
  account_ref: string | null
  form_ref: string
  form_name: string | null
  field_mapping: Record<string, string>
  is_active: boolean
}

export interface PageComparison {
  id: string
  name: string
  status: string
  path: string | null
  views: number
  leads: number
  conversion_rate: number | null
  qualified: number
  appointments: number
  sales: number
  revenue_minor: number
}

export const LEAD_FORM_PROVIDERS = ['meta', 'tiktok', 'snapchat', 'google_ads', 'linkedin'] as const
export const MAPPING_TARGETS = ['full_name', 'phone', 'email', 'city', 'budget_max', 'purchase_purpose', 'payment_method', 'preferred_property_type', 'preferred_location', 'purchase_timeline'] as const

const base = (projectId: string) => `/projects/${projectId}/lead-sources`

export const fetchLeadSources = (projectId: string) => apiGet<{ sources: LeadSource[]; totals: { sources: number; connected: number; leads: number; sales: number } }>(base(projectId))
export const fetchPageComparison = (projectId: string) => apiGet<{ days: number; pages: PageComparison[] }>(`${base(projectId)}/compare`)
export const createLeadFormMapping = (projectId: string, body: { provider: string; form_ref: string; form_name?: string | null; field_mapping?: Record<string, string> }) => apiPost<{ mapping: LeadFormMapping }>(`${base(projectId)}/mappings`, body)
export const updateLeadFormMapping = (projectId: string, id: string, body: Partial<Pick<LeadFormMapping, 'is_active' | 'field_mapping' | 'form_name'>>) => apiPatch<{ mapping: LeadFormMapping }>(`${base(projectId)}/mappings/${id}`, body)
export const deleteLeadFormMapping = (projectId: string, id: string) => apiDelete(`${base(projectId)}/mappings/${id}`)
export const setProjectLogo = (projectId: string, url: string | null) => apiPatch<{ logo_url: string | null }>(`${base(projectId)}/logo`, { url })
