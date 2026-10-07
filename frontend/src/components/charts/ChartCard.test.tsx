import { describe, expect, it, beforeEach } from 'vitest'
import { screen } from '@testing-library/react'
import { ChartCard } from './ChartCard'
import { renderWithProviders } from '@/test/utils'
import i18n from '@/i18n'

/**
 * "No data yet" and "this did not load" lead somewhere different.
 *
 * Every card collapsed a failed request into its empty state, so a report a
 * sales rep is not allowed to see read "لا توجد بيانات كافية لعرض هذا التقرير"
 * — the product stating a reason that was not true. One of those is a fact
 * about the workspace; the other is something to retry, or to ask for access
 * to.
 */
describe('ChartCard states', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('ar')
  })

  it('says what went wrong instead of claiming there is no data', () => {
    renderWithProviders(
      <ChartCard title="تقرير" error="ليس لديك صلاحية" empty emptyMessage="لا توجد بيانات">
        <div>chart</div>
      </ChartCard>,
    )

    expect(screen.getByText('ليس لديك صلاحية')).toBeInTheDocument()
    expect(screen.queryByText('لا توجد بيانات')).not.toBeInTheDocument()
  })

  it('still shows the empty message when nothing failed', () => {
    renderWithProviders(
      <ChartCard title="تقرير" empty emptyMessage="لا توجد بيانات">
        <div>chart</div>
      </ChartCard>,
    )

    expect(screen.getByText('لا توجد بيانات')).toBeInTheDocument()
  })

  it('draws the chart when there is something to draw', () => {
    renderWithProviders(
      <ChartCard title="تقرير">
        <div>chart</div>
      </ChartCard>,
    )

    expect(screen.getByText('chart')).toBeInTheDocument()
  })

  /** Loading wins: a request in flight has not failed yet. */
  it('prefers the skeleton while loading', () => {
    renderWithProviders(
      <ChartCard title="تقرير" loading error="ليس لديك صلاحية">
        <div>chart</div>
      </ChartCard>,
    )

    expect(screen.queryByText('ليس لديك صلاحية')).not.toBeInTheDocument()
  })

  /** A table of rows that never arrived is not worth offering. */
  it('hides the table toggle on a card that failed', () => {
    renderWithProviders(
      <ChartCard
        title="تقرير"
        testId="c"
        error="ليس لديك صلاحية"
        table={{ columns: ['أ'], rows: [['1']] }}
      >
        <div>chart</div>
      </ChartCard>,
    )

    expect(screen.queryByTestId('c-table-toggle')).not.toBeInTheDocument()
  })
})
