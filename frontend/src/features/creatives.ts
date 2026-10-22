import { useQuery } from '@tanstack/react-query'
import { apiGet, apiPatch, apiPost } from '@/lib/api'
import type { DraftView } from '@/features/agentChat'

export interface CreativeRow {
  id: string
  name: string
  status: 'active' | 'paused' | 'ended' | 'unknown'
  platform: string | null
  campaign: { id: string; name: string } | null
  ad_set: { id: string; name: string } | null
  on_platform: boolean
  creative: { external_id: string | null; format: string | null; headline: string | null; body: string | null; thumbnail_url: string | null } | null
  spend: number | null
  impressions: number | null
  clicks: number | null
  ctr: number | null
  leads: number
  qualified: number
  won: number
  revenue: number
  cpql: number | null
  fatigue: number | null
  drafts: number
}

export function useCreatives(days: number, platform: string) {
  return useQuery({
    queryKey: ['creatives', days, platform],
    queryFn: () => apiGet<{ creatives: CreativeRow[]; currency: string }>('/creatives', { days, platform: platform || undefined }),
  })
}

export function useDrafts(adId: string | null) {
  return useQuery({
    queryKey: ['creative-drafts', adId],
    queryFn: () => apiGet<{ drafts: DraftView[] }>(`/creatives/${adId}/drafts`).then((r) => r.drafts),
    enabled: adId !== null,
  })
}

export const generateDrafts = (adId: string, kind: DraftView['kind']) => apiPost<{ drafts: DraftView[] }>(`/creatives/${adId}/generate`, { kind })
export const writeDraft = (adId: string, kind: DraftView['kind'], items: string[]) => apiPost<{ draft: DraftView }>(`/creatives/${adId}/drafts`, { kind, items })
export const setDraftStatus = (id: string, status: 'used' | 'dismissed') => apiPatch<{ draft: DraftView }>(`/creative-drafts/${id}`, { status })
