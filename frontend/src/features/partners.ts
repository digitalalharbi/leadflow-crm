import { apiDelete, apiGet, apiPatch, apiPost } from '@/lib/api'

export const PARTNER_TYPES = ['broker', 'influencer', 'affiliate', 'external_agent'] as const
export type PartnerType = (typeof PARTNER_TYPES)[number]

export interface PartnerStats {
  links: number
  clicks: number
  leads: number
  qualified: number
  interested: number
  visits: number
  reservations: number
  won: number
  /** Absent when this role may not see revenue. */
  revenue?: number
  /** Null until a percentage has been agreed; absent when this role may not see revenue. */
  commission?: number | null
  last_lead_at: string | null
}

export interface Partner {
  id: string
  name: string
  type: PartnerType
  company: string | null
  contact_name: string | null
  email: string | null
  phone: string | null
  commission_percent: number | null
  show_revenue: boolean
  is_active: boolean
  notes: string | null
  stats: PartnerStats
}

export interface PartnerInput {
  name: string
  type: PartnerType
  company?: string | null
  contact_name?: string | null
  email?: string | null
  phone?: string | null
  commission_percent?: number | null
  show_revenue?: boolean
  is_active?: boolean
  notes?: string | null
}

export function fetchPartners(days = 90) {
  return apiGet<{ partners: Partner[]; types: PartnerType[] }>('/partners', { days })
}

export function createPartner(payload: PartnerInput) {
  return apiPost<{ partner: Partner }>('/partners', payload)
}

export function updatePartner(id: string, payload: Partial<PartnerInput>) {
  return apiPatch<{ partner: Partner }>(`/partners/${id}`, payload)
}

export function deletePartner(id: string) {
  return apiDelete<null>(`/partners/${id}`)
}
