import { apiDelete, apiGet, apiPatch, apiPost } from '@/lib/api'

/** A company's keys to the public API (`/api/v1/public`). The full key is returned once, by `createApiKey`. */
export const API_SCOPES = ['leads:read', 'leads:write', 'projects:read', 'visits:read', 'reservations:read', 'revenue:read'] as const

export type ApiScope = (typeof API_SCOPES)[number]

export interface ApiKeyRow {
  id: string
  name: string
  prefix: string
  scopes: ApiScope[]
  /** Addresses or CIDR ranges allowed to use the key; empty means any. */
  allowed_ips?: string[]
  /** Calls a minute this key may make. */
  rate_limit_per_minute?: number
  expires_at: string | null
  last_used_at: string | null
  revoked_at: string | null
  created_by: string | null
  created_at: string
  status: 'active' | 'expired' | 'revoked'
}

export const fetchApiKeys = () => apiGet<ApiKeyRow[]>('/api-keys')

export const createApiKey = (body: { name: string; scopes: ApiScope[]; expires_in_days?: number | null; allowed_ips?: string[]; rate_limit_per_minute?: number }) =>
  apiPost<{ key: ApiKeyRow; plain: string }>('/api-keys', body)

export const revokeApiKey = (id: string) => apiDelete<ApiKeyRow>(`/api-keys/${id}`)

/** A new secret for the same key; the old one stops working at once. */
export const rotateApiKey = (id: string) => apiPost<{ key: ApiKeyRow; plain: string }>(`/api-keys/${id}/rotate`)

export const setApiKeyIps = (id: string, allowed_ips: string[]) => apiPatch<ApiKeyRow>(`/api-keys/${id}`, { allowed_ips })

/** "1.2.3.4, 10.0.0.0/8" → ['1.2.3.4', '10.0.0.0/8'] */
export const parseIps = (text: string) => text.split(/[\s,]+/).map((ip) => ip.trim()).filter(Boolean)
