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
      won: 'مكسوب',
      lost: 'مفقود',
    }

    for (const [status, label] of Object.entries(labels)) {
      const { unmount } = render(<StatusBadge status={status as never} />)
      expect(screen.getByText(label)).toBeInTheDocument()
      unmount()
    }
  })

  it('renders each temperature', () => {
    for (const temp of ['hot', 'warm', 'cold'] as const) {
      const { unmount } = render(<TemperatureBadge temperature={temp} />)
      expect(screen.getByText(temp === 'hot' ? 'Hot' : temp === 'warm' ? 'Warm' : 'Cold')).toBeInTheDocument()
      unmount()
    }
  })

  it('gives won and lost visually distinct tones', () => {
    const { container: won } = render(<StatusBadge status="won" />)
    const { container: lost } = render(<StatusBadge status="lost" />)

    expect(won.firstElementChild?.className).not.toBe(lost.firstElementChild?.className)
  })
})
