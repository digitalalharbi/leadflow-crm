import { apiDelete, apiGet, apiPost } from '@/lib/api'
import type { CampaignSpend } from '@/types/api'

/**
 * What the ads cost, entered by whoever can see the ad account.
 *
 * Not waiting for platform credentials: a manager with the figures in front of
 * them can enter a week in a minute, and the same rows are what an insights
 * sync would write later. Amounts cross the wire in major units — what the ad
 * account shows and what a person types — and are stored in minor ones.
 */
export interface SpendEntry {
  platform: string
  campaign_name: string
  spend_date: string
  amount: number
}

export function fetchSpend(params: Record<string, unknown> = {}) {
  return apiGet<{ spends: CampaignSpend[]; currency: string }>('/campaign-spend', params)
}

export function saveSpend(spends: SpendEntry[]) {
  return apiPost<{ saved: number }>('/campaign-spend', { spends })
}

export function removeSpend(id: number) {
  return apiDelete(`/campaign-spend/${id}`)
}
