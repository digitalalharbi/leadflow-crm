import { describe, expect, it } from 'vitest'
import { campaignInsights, spendTrend, type CampaignRow, type CampaignTotals } from './campaigns'

const base: CampaignRow = {
  id: 'x', name: 'A', platform: 'meta', status: 'active', origin: 'sync', objective: null, project: null, account: null,
  daily_budget: null, currency: 'SAR', last_synced_at: null, spend_source: 'platform', impressions: null, clicks: null, ctr: null,
  daily: [], leads: 50, valid: 45, engaged: 20, qualified: 10, appointments: 4, won: 2, cold: 10, hot: 5, revenue: 0,
  spend: 1000, cpl: 20, cpql: 100, cost_per_appointment: 250, cpa: 500, roas: null, revenue_per_lead: null,
  qualified_rate: 20, won_rate: 4, cold_rate: 20,
}
const totals = { cpl: 20 } as CampaignTotals
const row = (patch: Partial<CampaignRow>): CampaignRow => ({ ...base, ...patch })

describe('campaign insights', () => {
  it('judges nothing on fewer than ten leads', () => {
    expect(campaignInsights([row({ leads: 9, qualified: 0 })], totals)).toEqual([])
  })

  it('separates no customers at all from plenty of forms and few customers', () => {
    const none = campaignInsights([row({ qualified: 0, qualified_rate: 0 })], totals)
    const forms = campaignInsights([row({ qualified: 3, qualified_rate: 6 })], totals)
    expect(none[0]).toMatchObject({ key: 'no_qualified', tone: 'bad' })
    expect(forms[0]).toMatchObject({ key: 'forms_not_customers', tone: 'warn' })
  })

  it('recognises a campaign worth scaling and a lead that costs too much', () => {
    expect(campaignInsights([row({ qualified_rate: 55 })], totals)[0].key).toBe('scale')
    expect(campaignInsights([row({ cpl: 40 })], totals).map((i) => i.key)).toContain('costly_lead')
  })

  it('asks for a cost when there is none, and puts the worst first', () => {
    const out = campaignInsights([row({ qualified_rate: 55, spend: null }), row({ id: 'y', qualified: 0, qualified_rate: 0 })], totals)
    expect(out.map((i) => i.key)).toEqual(['no_qualified', 'unpriced', 'scale'].filter((k) => out.some((i) => i.key === k)))
    expect(out[0].tone).toBe('bad')
  })
})

describe('spend trend', () => {
  it('sums campaigns by day and fills the quiet ones with zero', () => {
    const rows = [
      row({ daily: [{ date: '2026-10-01', spend: 10 }, { date: '2026-10-03', spend: 5 }] }),
      row({ id: 'y', daily: [{ date: '2026-10-01', spend: 2 }] }),
    ]
    expect(spendTrend(rows, '2026-10-01', '2026-10-03')).toEqual([
      { date: '2026-10-01', value: 12 },
      { date: '2026-10-02', value: 0 },
      { date: '2026-10-03', value: 5 },
    ])
  })
})
