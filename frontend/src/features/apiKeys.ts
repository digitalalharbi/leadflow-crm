import { apiDelete, apiGet, apiPost } from '@/lib/api'

/** A company's keys to the public API (`/api/v1/public`). The full key is returned once, by `createApiKey`. */
export const API_SCOPES = ['leads:read', 'leads:write', 'projects:read', 'visits:read', 'reservations:read', 'revenue:read'] as const

export type ApiScope = (typeof API_SCOPES)[number]

export interface ApiKeyRow {
  id: string
  name: string
  prefix: string
  scopes: ApiScope[]
  expires_at: string | null
  last_used_at: string | null
  revoked_at: string | null
  created_by: string | null
  created_at: string
  status: 'active' | 'expired' | 'revoked'
}

export const fetchApiKeys = () => apiGet<ApiKeyRow[]>('/api-keys')

export const createApiKey = (body: { name: string; scopes: ApiScope[]; expires_in_days?: number | null }) =>
  apiPost<{ key: ApiKeyRow; plain: string }>('/api-keys', body)

export const revokeApiKey = (id: string) => apiDelete<ApiKeyRow>(`/api-keys/${id}`)
