import { can, useAuth } from '@/stores/auth'
import { useQuery } from '@tanstack/react-query'
import { apiDelete, apiGet, apiPatch, apiPost, apiPut } from '@/lib/api'

export interface Company {
  id: string
  name: string
  description: string | null
  color: string | null
  is_active: boolean
  projects_count?: number
  members?: { id: string; name: string }[]
}

export interface CompanyMetrics {
  id: string
  leads: number
  open: number
  hot: number
  won: number
  new_30d: number
  conversion_rate: number
}

export interface CompanyProjectRow {
  id: string
  name: string
  status: string
  location: string | null
  leads: number
  open: number
  hot: number
  won: number
  new_30d: number
  conversion_rate: number
}

export interface CompaniesResponse {
  companies: Company[]
  metrics: CompanyMetrics[]
  /** Whether this person is held to some of the companies. */
  restricted: boolean
}

export const COMPANIES_KEY = ['companies'] as const

export function fetchCompanies(all = false) {
  return apiGet<CompaniesResponse>('/companies', all ? { all: true } : undefined)
}

/** The holding's companies, shared by the switcher, the overview and the form. */
export function useCompanies() {
  // Asked only by someone allowed to read it: a role without the permission got a 403 on every page.
  const allowed = useAuth((s) => !s.isSuperAdmin && can.has(s.user, 'companies.view'))

  return useQuery({ queryKey: COMPANIES_KEY, queryFn: () => fetchCompanies(), staleTime: 60_000, enabled: allowed })
}

export type CompanyPayload = Partial<Pick<Company, 'name' | 'description' | 'color' | 'is_active'>>

export function createCompany(payload: CompanyPayload) {
  return apiPost<{ company: Company }>('/companies', payload)
}

export function updateCompany(id: string, payload: CompanyPayload) {
  return apiPatch<{ company: Company }>(`/companies/${id}`, payload)
}

export function deleteCompany(id: string) {
  return apiDelete(`/companies/${id}`)
}

export function setCompanyMembers(id: string, userIds: string[]) {
  return apiPut<{ company: Company }>(`/companies/${id}/members`, { user_ids: userIds })
}

export function fetchCompanyProjects(id: string) {
  return apiGet<{ company: Company; projects: CompanyProjectRow[] }>(`/companies/${id}/projects`)
}
