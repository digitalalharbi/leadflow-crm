import { describe, expect, it, beforeAll } from 'vitest'
import { render, screen } from '@testing-library/react'
import { StatusBadge, TemperatureBadge } from './Badge'
import i18n from '@/i18n'

describe('status and temperature badges', () => {
  beforeAll(async () => {
    await i18n.changeLanguage('ar')
  })

  it('renders every pipeline stage with its Arabic label', () => {
    const labels: Record<string, string> = {
      new: 'جديد',
      contacted: 'تم التواصل',
      qualified: 'مؤهل',
      appointment: 'موعد',
      won: 'تم البيع',
      lost: 'فرصة خاسرة',
    }

    for (const [status, label] of Object.entries(labels)) {
      const { unmount } = render(<StatusBadge status={status as never} />)
      expect(screen.getByText(label)).toBeInTheDocument()
      unmount()
    }
  })

  /**
   * The interest scale is Arabic, and parallel.
   *
   * It was Hot / Warm / Cold — three English words in an Arabic interface,
   * sitting in a funnel list beside تم التواصل، ردوا، مؤهلون، مواعيد، مبيعات.
   * Every other category in the product is Arabic; this one was not, and it
   * read as an untranslated string rather than a decision.
   */
  it('renders each level of interest in Arabic', () => {
    const levels: Record<string, string> = { hot: 'مرتفع', warm: 'متوسط', cold: 'منخفض' }

    for (const temp of ['hot', 'warm', 'cold'] as const) {
      const { unmount } = render(<TemperatureBadge temperature={temp} />)

      const badge = screen.getByText(levels[temp])
      expect(badge).toBeInTheDocument()
      // No Latin letters: a missing key would render the key itself.
      expect(badge.textContent).not.toMatch(/[A-Za-z]/)

      unmount()
    }
  })

  it('gives won and lost visually distinct tones', () => {
    const { container: won } = render(<StatusBadge status="won" />)
    const { container: lost } = render(<StatusBadge status="lost" />)

    expect(won.firstElementChild?.className).not.toBe(lost.firstElementChild?.className)
  })
})
