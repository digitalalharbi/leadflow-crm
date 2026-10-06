import { beforeAll, describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { VelocityChart } from './VelocityChart'
import i18n from '@/i18n'

/**
 * The empty step is the case that matters, and it is the one real data stops
 * exercising the moment the fixtures are any good.
 *
 * It used to be covered by an end-to-end test shaped `if (text.includes(...))`,
 * which asserted nothing at all on a run where every step had data — and after
 * the fixtures were fixed, that was every run. Here it is stated outright.
 */
describe('a step nobody has reached', () => {
  beforeAll(async () => {
    await i18n.changeLanguage('ar')
  })

  it('says so, and never shows a zero', () => {
    render(
      <VelocityChart
        steps={[
          { step: 'to_contact', median_hours: 0.2, count: 27 },
          { step: 'to_qualify', median_hours: null, count: 0 },
          { step: 'to_win', median_hours: null, count: 0 },
        ]}
      />,
    )

    expect(screen.getAllByText('لم يجتز هذه الخطوة أحد بعد')).toHaveLength(2)
    expect(screen.getAllByText('—')).toHaveLength(2)

    /*
     * "0" there would read as "instant", which is the exact opposite of the
     * truth: nobody has made this step at all.
     */
    expect(screen.queryByText('0')).toBeNull()
    expect(screen.queryByText(/^0 /)).toBeNull()
  })

  it('renders nothing at all when no step has been measured', () => {
    const { container } = render(
      <VelocityChart
        steps={[
          { step: 'to_contact', median_hours: null, count: 0 },
          { step: 'to_qualify', median_hours: null, count: 0 },
        ]}
      />,
    )

    expect(container).toBeEmptyDOMElement()
  })

  /**
   * Both charts that show a duration now go through one formatter. They did
   * not: this chart turned 72 hours into "3 أيام" while the team chart turned
   * the same 72 hours into "72 ساعة", because its own ladder stopped at hours.
   */
  it('uses the product-wide scale, which reaches days', () => {
    render(
      <VelocityChart steps={[{ step: 'to_win', median_hours: 72, count: 5 }]} />,
    )

    expect(screen.getByText('3 أيام')).toBeInTheDocument()
  })

  it('states the sample size with the right Arabic plural', () => {
    render(
      <VelocityChart
        steps={[
          { step: 'to_contact', median_hours: 1, count: 1 },
          { step: 'to_qualify', median_hours: 1, count: 2 },
          { step: 'to_win', median_hours: 1, count: 5 },
        ]}
      />,
    )

    expect(screen.getByText('بناءً على عميل واحد')).toBeInTheDocument()
    expect(screen.getByText('بناءً على عميلين')).toBeInTheDocument()
    expect(screen.getByText('بناءً على 5 عملاء')).toBeInTheDocument()
  })
})
