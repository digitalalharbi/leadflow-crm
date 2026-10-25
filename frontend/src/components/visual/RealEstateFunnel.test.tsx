import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { RealEstateFunnel } from './RealEstateFunnel'
import type { FunnelStage, RealEstateFunnelData } from '@/features/reportShares'

const stage = (key: FunnelStage['key'], count: number | null, extra: Partial<FunnelStage> = {}): FunnelStage => ({
  key, label: key, available: count !== null, count, rate: null, drop_off: null, previous: null, change: null, sub_stages: [], ...extra,
})

const funnel = (stages: FunnelStage[], lost = { count: 0, rate: null as number | null }): RealEstateFunnelData => ({
  stages, period: { from: '2026-10-01', to: '2026-10-30', days: 30 }, previous_period: { from: '2026-09-01', to: '2026-09-30' }, trend: null, lost, generated_at: '2026-10-30T00:00:00Z',
})

const full = [
  stage('awareness', 5000, { basis: 'platform' }),
  stage('interest', 100, { rate: 2, change: 25 }),
  stage('engagement', 60, { rate: 60, drop_off: 40 }),
  stage('booking', 20, { rate: 33.3, drop_off: 66.7 }),
  stage('decision', 5, { rate: 25, drop_off: 75, revenue: 900000 }),
  stage('referral', 7, { subset_of_previous: false }),
]

describe('RealEstateFunnel', () => {
  it('draws the six official stages, in order, in Arabic', () => {
    renderWithProviders(<RealEstateFunnel funnel={funnel(full)} />)

    const rows = screen.getAllByRole('listitem')
    expect(rows.map((r) => r.getAttribute('data-testid'))).toEqual(['re-funnel-awareness', 're-funnel-interest', 're-funnel-engagement', 're-funnel-booking', 're-funnel-decision', 're-funnel-referral'])
    for (const label of ['وعي', 'اهتمام', 'تفاعل', 'حجز معاينة', 'قرار شراء', 'إحالة']) expect(screen.getByTestId('re-funnel')).toHaveTextContent(label)
  })

  it('says whose number awareness is', () => {
    renderWithProviders(<RealEstateFunnel funnel={funnel(full)} />)
    expect(screen.getByTestId('re-funnel-basis')).toHaveTextContent('المنصات الإعلانية')
  })

  it('shows a stage with no data as not available — never as a bar that reads zero', () => {
    renderWithProviders(<RealEstateFunnel funnel={funnel([stage('awareness', null, { reason: 'no_traffic_data' }), ...full.slice(1)])} />)

    const awareness = screen.getByTestId('re-funnel-awareness')
    expect(awareness).toHaveAttribute('data-available', 'false')
    expect(awareness).toHaveTextContent('غير متاح')
    expect(awareness.querySelector('[data-chart-mark]')).toBeNull()
  })

  it('sets referral apart: it is not a share of the stage before', () => {
    renderWithProviders(<RealEstateFunnel funnel={funnel(full)} />)

    expect(screen.getByTestId('re-funnel-referral')).toHaveTextContent('ليست جزءًا من المرحلة السابقة')
    expect(screen.queryByTestId('re-funnel-rate-referral')).toBeNull()
  })

  it('carries the conversion, the change against the previous period, and revenue only when it may', () => {
    const { rerender } = renderWithProviders(<RealEstateFunnel funnel={funnel(full)} />)
    expect(screen.getByTestId('re-funnel-rate-engagement')).toHaveTextContent('60%')
    expect(screen.getByTestId('re-funnel-decision')).toHaveTextContent('900')

    rerender(<RealEstateFunnel funnel={funnel(full)} showRevenue={false} />)
    expect(screen.getByTestId('re-funnel-decision')).not.toHaveTextContent('الإيرادات')
  })

  it('names what a stage cost when the spend is known', () => {
    renderWithProviders(<RealEstateFunnel funnel={funnel([...full.slice(0, 1), stage('interest', 100, { cost: 150 }), ...full.slice(2)])} />)
    expect(screen.getByTestId('re-funnel-cost-interest')).toHaveTextContent('تكلفة المرحلة')
  })

  it('reports lost opportunities in plain words', () => {
    renderWithProviders(<RealEstateFunnel funnel={funnel(full, { count: 12, rate: 12 })} />)
    expect(screen.getByTestId('re-funnel-lost')).toHaveTextContent('فرص خاسرة')
  })
})
