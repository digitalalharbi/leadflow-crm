import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { FunnelBars } from './FunnelBars'
import type { FunnelRow } from '@/features/hub'

const rows: FunnelRow[] = [
  { key: 'click', count: 1000, drop_off: null, of_start: 100 },
  { key: 'view', count: 800, drop_off: 20, of_start: 80 },
  { key: 'form_start', count: 200, drop_off: 75, of_start: 20 },
  { key: 'submit', count: 0, drop_off: 100, of_start: 0 },
]

describe('FunnelBars', () => {
  it('draws every step — a step nobody reached is the finding — with its count', () => {
    renderWithProviders(<FunnelBars rows={rows} />)

    expect(screen.getAllByRole('listitem')).toHaveLength(4)
    expect(screen.getByTestId('funnel-submit-count')).toHaveTextContent(/^(0|٠)$/)
  })

  it('marks the biggest loss, and only that one', () => {
    renderWithProviders(<FunnelBars rows={rows} />)

    expect(screen.getByTestId('funnel-submit')).toHaveAttribute('data-worst')
    expect(screen.getByTestId('funnel-form_start')).not.toHaveAttribute('data-worst')
  })

  it('says nothing about a loss for the first step', () => {
    renderWithProviders(<FunnelBars rows={rows} />)

    expect(screen.queryByTestId('funnel-click-drop')).toBeNull()
  })
})
