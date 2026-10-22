import { apiDelete, apiGet, apiPatch, apiPost } from '@/lib/api'

export const PARTNER_TYPES = ['broker', 'influencer', 'affiliate', 'external_agent'] as const
export type PartnerType = (typeof PARTNER_TYPES)[number]

export const COMMISSION_TYPES = ['percentage', 'fixed'] as const
export type CommissionType = (typeof COMMISSION_TYPES)[number]

export interface CommissionLedger {
  /** Null until terms have been agreed. */
  due: number | null
  paid: number
  balance: number | null
  deals: number
  revenue: number
}

export interface CommissionPayment {
  id: string
  amount: number
  paid_on: string
  reference: string | null
  note: string | null
}

export interface PartnerStatement {
  partner: Partner
  terms: { type: CommissionType; percent: number | null; fixed: number | null; agreed: boolean }
  stats: PartnerStats
  ledger: CommissionLedger
  deals: { lead: string; lead_id: string; won_at: string | null; value: number; commission: number | null }[]
  commission_payments: CommissionPayment[]
  days: number
}

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
  /** How what they are owed is worked out: a share of each sale, or a fixed amount per sale. */
  commission_type: CommissionType
  commission_percent: number | null
  commission_fixed: number | null
  show_revenue: boolean
  is_active: boolean
  notes: string | null
  stats: PartnerStats
  /** All-time: what is owed, what has been paid, what is left. Absent when this role may not see revenue. */
  ledger?: CommissionLedger
}

export interface PartnerInput {
  name: string
  type: PartnerType
  company?: string | null
  contact_name?: string | null
  email?: string | null
  phone?: string | null
  commission_type?: CommissionType
  commission_percent?: number | null
  commission_fixed?: number | null
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

export const fetchStatement = (id: string, days = 90) => apiGet<PartnerStatement>(`/partners/${id}/statement`, { days })

export const recordPayment = (id: string, body: { amount: number; paid_on?: string; reference?: string; note?: string }) =>
  apiPost<{ payment: CommissionPayment; ledger: CommissionLedger }>(`/partners/${id}/commission-payments`, body)

export const removePayment = (id: string, paymentId: string) => apiDelete<{ ledger: CommissionLedger }>(`/partners/${id}/commission-payments/${paymentId}`)

/** The partner's own statement, behind a link only they hold. Asking again returns the same link. */
export const statementLink = (id: string) => apiPost<{ share: { id: string; url: string; status: string; view_count: number; last_viewed_at: string | null } }>(`/partners/${id}/statement-link`)
