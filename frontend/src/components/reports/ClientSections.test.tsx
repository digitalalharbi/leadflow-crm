import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { AttentionSection, MarketingSection, NotesSection, RevenueSection, SalesSection } from './ClientSections'
import { AttributionSection, CampaignsSection, TeamSection, useReportCtx } from './ReportSections'
import type { SharedReportData } from '@/features/reportShares'

const data = (extra: Partial<SharedReportData>): SharedReportData => ({
  report: { name: 'تقرير', template: 'management', days: 30, from: '2026-10-01', to: '2026-10-30', generated_at: '2026-10-30T10:00:00Z', currency: 'SAR', compare: false, refresh_seconds: 60 },
  sections: [], workspace: { name: 'شركة', color: null, logo_url: null }, ...extra,
})

function View({ d, children }: { d: SharedReportData; children: (ctx: ReturnType<typeof useReportCtx>) => React.ReactNode }) {
  const ctx = useReportCtx(d)

  return <>{children(ctx)}</>
}

describe('client report sections', () => {
  it('marketing keeps platform-reported and Mohtam-attributed numbers in separate blocks', () => {
    const d = data({ marketing: { has_ad_data: true, platform_reported: [{ key: 'meta', spend: 1000, impressions: 20000, clicks: 400, ctr: 2, cpc: 2.5 }], mohtam_attributed: [{ key: 'meta', leads: 6, qualified: 3, interested: 2, visits: 1, reservations: 1, won: 1, visit_rate: 16.7, cpl: 166.67, cac: 1000, revenue: 900000, roas: 900 }] } })
    renderWithProviders(<View d={d}>{(ctx) => <MarketingSection ctx={ctx} />}</View>)

    expect(screen.getByTestId('shared-marketing-reported')).toHaveTextContent('ما أبلغت عنه المنصات الإعلانية')
    expect(screen.getByTestId('shared-marketing-attributed')).toHaveTextContent('ما نُسب إلى مصادر العملاء في مهتم')
    // Leads are Mohtam's number: not in the platform block.
    expect(screen.getByTestId('shared-reported-table')).not.toHaveTextContent('العملاء المحتملون')
    expect(screen.queryByTestId('shared-marketing-noads')).toBeNull()
  })

  it('with no ad account it says so, in the words the product promises', () => {
    renderWithProviders(<View d={data({ marketing: { has_ad_data: false, platform_reported: [], mohtam_attributed: [] } })}>{(ctx) => <MarketingSection ctx={ctx} />}</View>)
    expect(screen.getByTestId('shared-marketing-noads')).toHaveTextContent('لم يتم ربط حساب إعلاني بعد. يتم عرض النتائج حسب مصدر العملاء المسجل في مهتم.')
  })

  it('revenue with no sales says so instead of a zero', () => {
    renderWithProviders(<View d={data({ revenue: { deals: 0, total: null, average_deal: null, by_source: [], by_project: [] } })}>{(ctx) => <RevenueSection ctx={ctx} />}</View>)
    expect(screen.getByTestId('shared-revenue-empty')).toHaveTextContent('لا توجد مبيعات مسجلة خلال الفترة المحددة.')
  })

  it('campaigns with none linked says so', () => {
    renderWithProviders(<View d={data({ campaigns: { total: 0, rows: [] } })}>{(ctx) => <CampaignsSection ctx={ctx} />}</View>)
    expect(screen.getByTestId('shared-campaigns')).toHaveTextContent('لا توجد حملات مرتبطة خلال هذه الفترة.')
  })

  it('a campaign figure that cannot be worked out is “not available”, never a zero', () => {
    const row = { name: 'حملة', platform: 'meta', leads: 0, qualified: 0, interested: 0, visits: 0, reservations: 0, won: 0, spend: 500, cpl: null }
    renderWithProviders(<View d={data({ campaigns: { total: 1, rows: [row] } })}>{(ctx) => <CampaignsSection ctx={ctx} />}</View>)
    expect(screen.getByTestId('shared-campaign-row')).toHaveTextContent('غير متاح')
  })

  it('attribution always names the model, and offers the others only when the plan sent them', () => {
    const row = { key: 'meta', label: 'meta', leads: 2, interested: 1, visits: 1, reservations: 0, won: 0 }
    const { unmount } = renderWithProviders(<View d={data({ attribution: { model: 'linear', rows: [row] } })}>{(ctx) => <AttributionSection ctx={ctx} />}</View>)
    expect(screen.getByTestId('shared-attr-model')).toHaveTextContent('خطي')
    expect(screen.queryByTestId('shared-attr-first')).toBeNull()
    unmount()

    renderWithProviders(<View d={data({ attribution: { model: 'last', rows: [row], models: { first: [row], last: [row], linear: [row], position: [row] } } })}>{(ctx) => <AttributionSection ctx={ctx} />}</View>)
    expect(screen.getByTestId('shared-attr-model')).toHaveTextContent('آخر لمسة')
    expect(screen.getByTestId('shared-attr-first')).toBeInTheDocument()
  })

  it('the team table has names and rates only, and the revenue column only when it was sent', () => {
    const member = { name: 'سارة', leads: 10, contact_rate: 90, response_minutes: 12, follow_up_completion: 80, qualified: 5, interested: 4, visits: 2, reservations: 1, won: 1, conversion_rate: 10 }
    renderWithProviders(<View d={data({ team: [member] })}>{(ctx) => <TeamSection ctx={ctx} />}</View>)

    expect(screen.getByTestId('shared-team-row')).toHaveTextContent('سارة')
    expect(screen.getByTestId('shared-team-table')).not.toHaveTextContent('الإيرادات')
  })

  it('sales shows the answer-time figure as not available when nobody has answered', () => {
    const sales = { received: 3, assigned: 0, unassigned: 3, contacted: 0, not_contacted: 3, qualified: 0, interested: 0, follow_ups: 0, follow_ups_done: 0, overdue_follow_ups: 0, appointments: 0, visits: 0, reservations: 0, won: 0, lost: 0, avg_first_response_minutes: null, sla_breaches: 0, sla_target_minutes: 30 }
    renderWithProviders(<View d={data({ sales })}>{(ctx) => <SalesSection ctx={ctx} />}</View>)
    expect(screen.getByTestId('sales-response')).toHaveTextContent('غير متاح')
  })

  it('attention lists what needs a person, and says so when nothing does', () => {
    const { unmount } = renderWithProviders(<View d={data({ attention: [{ key: 'unassigned_leads', severity: 'warning', values: { n: 4 } }] })}>{(ctx) => <AttentionSection ctx={ctx} />}</View>)
    expect(screen.getByTestId('shared-attention-item')).toHaveTextContent('عملاء بلا مسؤول: 4')
    unmount()

    renderWithProviders(<View d={data({ attention: [] })}>{(ctx) => <AttentionSection ctx={ctx} />}</View>)
    expect(screen.getByTestId('shared-attention-empty')).toHaveTextContent('لا شيء يحتاج انتباهك الآن.')
  })

  it('notes are plain text: markup in a note is shown as text, never run', () => {
    renderWithProviders(<NotesSection data={data({ notes: [{ kind: 'executive', body: '<img src=x onerror=alert(1)> أداء جيد', at: '2026-10-30T00:00:00Z' }] })} />)

    expect(screen.getByTestId('shared-notes')).toHaveTextContent('<img src=x onerror=alert(1)> أداء جيد')
    expect(screen.getByTestId('shared-notes').querySelector('img')).toBeNull()
  })
})
