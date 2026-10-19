import { useQuery } from '@tanstack/react-query'
import { apiDelete, apiGet, apiPost, apiPut } from '@/lib/api'
import type { AdPlatformKey } from '@/features/campaigns'

export type LadderState = 'not_configured' | 'code_verified' | 'sandbox_verified' | 'ready' | 'live_verified' | 'production_verified'

export const LADDER: LadderState[] = ['not_configured', 'code_verified', 'sandbox_verified', 'ready', 'live_verified', 'production_verified']

export interface AdConnectionView {
  platform: AdPlatformKey
  built: boolean
  state: LadderState
  mode: 'live' | 'sandbox'
  required: string[]
  optional: string[]
  filled: string[]
  config: Record<string, unknown>
  capabilities: Record<string, { supported: boolean; reason?: string }>
  last_test_ok_at: string | null
  last_sync_ok_at: string | null
  last_error: string | null
  stand_in: boolean
}

export const AD_PLATFORMS: AdPlatformKey[] = ['meta', 'tiktok', 'google_ads', 'snapchat', 'x', 'linkedin']

export function useAdConnection(platform: AdPlatformKey) {
  return useQuery({
    queryKey: ['ad-connection', platform],
    queryFn: () => apiGet<{ connection: AdConnectionView }>(`/ad-connections/${platform}`).then((r) => r.connection),
  })
}

export const saveAdConnection = (platform: AdPlatformKey, body: { credentials?: Record<string, string>; config?: Record<string, unknown>; mode?: string }) =>
  apiPut<{ connection: AdConnectionView }>(`/ad-connections/${platform}`, body)

export const testAdConnection = (platform: AdPlatformKey) =>
  apiPost<{ ok: boolean; accounts?: number; error?: string; connection: AdConnectionView }>(`/ad-connections/${platform}/test`)

export const disconnectAdConnection = (platform: AdPlatformKey) => apiDelete(`/ad-connections/${platform}`)
