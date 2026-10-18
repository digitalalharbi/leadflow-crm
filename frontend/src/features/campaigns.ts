import { useQuery } from '@tanstack/react-query'
import { apiGet, apiPost } from '@/lib/api'

export type AdPlatformKey = 'meta' | 'tiktok' | 'google_ads' | 'snapchat' | 'x' | 'linkedin'

export interface CampaignRow {
  id: string
  name: string
  platform: AdPlatformKey
  status: 'active' | 'paused' | 'ended' | 'unknown'
  origin: 'sync' | 'lead_attribution' | 'manual'
  objective: string | null
  project: { id: string; name: string } | null
  account: string | null
  daily_budget: number | null
  currency: string | null
  last_synced_at: string | null
  /** 0–100, the Leads Agent's answer to "forms or customers?"; null until judged. */
  quality_score: number | null
  quality_previous: number | null
  spend_source: 'platform' | 'manual' | null
  impressions: number | null
  clicks: number | null
  ctr: number | null
  daily: { date: string; spend: number }[]
  leads: number
  valid: number
  engaged: number
  qualified: number
  appointments: number
  won: number
  cold: number
  hot: number
  revenue: number
  spend: number | null
  cpl: number | null
  cpql: number | null
  cost_per_appointment: number | null
  cpa: number | null
  roas: number | null
  revenue_per_lead: number | null
  qualified_rate: number | null
  won_rate: number | null
  cold_rate: number | null
}

export interface CampaignTotals {
  campaigns: number
  spend: number | null
  leads: number
  valid: number
  engaged: number
  qualified: number
  appointments: number
  won: number
  revenue: number
  cpl: number | null
  cpql: number | null
  cpa: number | null
  roas: number | null
  qualified_rate: number | null
  won_rate: number | null
  mixed_currencies: boolean
}

export interface CampaignsData {
  campaigns: CampaignRow[]
  totals: CampaignTotals
  currency: string
  from: string
  to: string
}

export interface PlatformState {
  key: AdPlatformKey
  built: boolean
  connected: boolean
  actions: string[]
  accounts: number
  last_synced_at: string | null
  last_error: string | null
}

export function useCampaigns(days: number, platform: string, projectId?: string, enabled = true) {
  return useQuery({
    queryKey: ['campaigns', days, platform, projectId ?? null],
    queryFn: () => apiGet<CampaignsData>('/campaigns', { days, platform: platform || undefined, project_id: projectId }),
    enabled,
  })
}

export function useCampaignPlatforms() {
  return useQuery({
    queryKey: ['campaign-platforms'],
    queryFn: () => apiGet<{ platforms: PlatformState[] }>('/campaigns/platforms'),
  })
}

export function syncCampaigns() {
  return apiPost<{ accounts: number; campaigns: number; ads: number; snapshots: number; errors: { platform: string; message: string }[] }>('/campaigns/sync')
}

/* ------------------------------------------------------------ insights */

export type InsightKey = 'forms_not_customers' | 'no_qualified' | 'costly_lead' | 'scale' | 'unpriced'

export interface Insight {
  key: InsightKey
  tone: 'bad' | 'warn' | 'good' | 'neutral'
  campaign: CampaignRow
}

const MIN_LEADS = 10
const ORDER: Record<Insight['tone'], number> = { bad: 0, warn: 1, neutral: 2, good: 3 }

/**
 * Which campaigns deserve a person's attention, and why — from the numbers
 * already on the screen, with thresholds stated here so they can be argued with.
 *
 * Nothing is judged on fewer than ten leads: a campaign with three leads and no
 * sales has not failed, it has not had time. And the headline finding is the one
 * a lead count cannot show — plenty of forms, almost no customers.
 */
export function campaignInsights(rows: CampaignRow[], totals: CampaignTotals): Insight[] {
  const insights: Insight[] = []

  for (const campaign of rows) {
    if (campaign.leads < MIN_LEADS) {
      continue
    }

    if (campaign.qualified === 0) {
      insights.push({ key: 'no_qualified', tone: 'bad', campaign })
    } else if (campaign.qualified_rate !== null && campaign.qualified_rate < 15) {
      insights.push({ key: 'forms_not_customers', tone: 'warn', campaign })
    } else if (campaign.qualified_rate !== null && campaign.qualified_rate >= 40) {
      insights.push({ key: 'scale', tone: 'good', campaign })
    }

    if (campaign.cpl !== null && totals.cpl !== null && campaign.cpl > totals.cpl * 1.5) {
      insights.push({ key: 'costly_lead', tone: 'warn', campaign })
    }

    if (campaign.spend === null) {
      insights.push({ key: 'unpriced', tone: 'neutral', campaign })
    }
  }

  return insights.sort((a, b) => ORDER[a.tone] - ORDER[b.tone] || b.campaign.leads - a.campaign.leads)
}

/** Daily spend summed across campaigns, over the whole window, zero-filled. */
export function spendTrend(rows: CampaignRow[], from: string, to: string): { date: string; value: number }[] {
  const byDate = new Map<string, number>()

  for (const row of rows) {
    for (const point of row.daily) {
      byDate.set(point.date, (byDate.get(point.date) ?? 0) + point.spend)
    }
  }

  const out: { date: string; value: number }[] = []
  const end = new Date(`${to}T00:00:00Z`)

  for (let day = new Date(`${from}T00:00:00Z`); day <= end; day.setUTCDate(day.getUTCDate() + 1)) {
    const key = day.toISOString().slice(0, 10)
    out.push({ date: key, value: byDate.get(key) ?? 0 })
  }

  return out
}

export interface CampaignAd {
  id: string
  name: string
  status: CampaignRow['status']
  ad_set: string | null
  /** 0–100, how far click-through fell lately; null when there is too little to say. */
  fatigue: number | null
  impressions: number | null
  clicks: number | null
  ctr: number | null
  creative: { format: string | null; headline: string | null; body: string | null; thumbnail_url: string | null } | null
  leads: number
  qualified: number
  won: number
  cold: number
  revenue: number
  spend: number | null
  cpl: number | null
  cpql: number | null
  qualified_rate: number | null
}

export interface CampaignDetailData {
  campaign: CampaignRow
  /** Per event, how many outcomes were reported back to the platform, by status. */
  feedback: Record<'qualified' | 'appointment' | 'won', Partial<Record<'sent' | 'pending' | 'awaiting_credentials' | 'failed' | 'unmatchable', number>>>
  ads: CampaignAd[]
  leads_daily: { date: string; count: number }[]
  classification: { key: import('@/types/api').ClassificationKey; count: number }[]
  currency: string
  from: string
  to: string
}

export function useCampaign(id: string, days: number) {
  return useQuery({
    queryKey: ['campaigns', 'detail', id, days],
    queryFn: () => apiGet<CampaignDetailData>(`/campaigns/${id}`, { days }),
    enabled: Boolean(id),
  })
}
