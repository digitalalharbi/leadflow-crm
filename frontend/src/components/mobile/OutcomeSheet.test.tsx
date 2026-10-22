import { describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { OutcomeSheet } from './OutcomeSheet'
import { OUTCOMES } from '@/features/work'
import { SHEET_OUTCOMES } from './OutcomeSheet'
import { renderWithProviders } from '@/test/utils'

function sheet(overrides: Partial<React.ComponentProps<typeof OutcomeSheet>> = {}) {
  const onSave = vi.fn(() => true)
  const onClose = vi.fn()

  renderWithProviders(<OutcomeSheet open onClose={onClose} onSave={onSave} pending={false} online {...overrides} />)

  return { onSave, onClose }
}

describe('OutcomeSheet', () => {
  it('offers every outcome the product records, "spoke to them" first, as large targets; saving waits for a choice', () => {
    sheet()

    for (const outcome of OUTCOMES) expect(screen.getByTestId(`outcome-${outcome}`)).toBeInTheDocument()
    expect([...SHEET_OUTCOMES].sort()).toEqual([...OUTCOMES].sort())
    expect(SHEET_OUTCOMES[0]).toBe('contacted')
    expect(screen.getByTestId('outcome-contacted')).toHaveTextContent('تم التواصل')
    expect(screen.getByTestId('outcome-save')).toBeDisabled()
    // Large: every choice carries the 56px-minimum class, comfortably over the 44px a thumb needs.
    expect(screen.getByTestId('outcome-interested').className).toContain('min-h-14')
  })

  it('saves the chosen outcome with its note, exactly once per press', () => {
    const { onSave } = sheet()

    fireEvent.click(screen.getByTestId('outcome-meeting_booked'))
    fireEvent.change(screen.getByTestId('outcome-note'), { target: { value: 'يريد الزيارة السبت' } })
    fireEvent.click(screen.getByTestId('outcome-save'))

    expect(onSave).toHaveBeenCalledTimes(1)
    expect(onSave).toHaveBeenCalledWith('meeting_booked', 'يريد الزيارة السبت')
  })

  it('cannot be saved while a save is in flight, so an outcome is never recorded twice', () => {
    const { onSave } = sheet({ pending: true })

    fireEvent.click(screen.getByTestId('outcome-won'))
    expect(screen.getByTestId('outcome-save')).toBeDisabled()
    fireEvent.click(screen.getByTestId('outcome-save'))

    expect(onSave).not.toHaveBeenCalled()
  })

  it('says so, and refuses to save, with no connection — nothing is queued behind the rep’s back', () => {
    const { onSave } = sheet({ online: false })

    fireEvent.click(screen.getByTestId('outcome-won'))

    expect(screen.getByTestId('outcome-offline')).toHaveTextContent('لا يوجد اتصال')
    expect(screen.getByTestId('outcome-save')).toBeDisabled()
    fireEvent.click(screen.getByTestId('outcome-save'))
    expect(onSave).not.toHaveBeenCalled()
  })

  it('marks the chosen outcome for assistive technology', () => {
    sheet()

    fireEvent.click(screen.getByTestId('outcome-lost'))

    expect(screen.getByTestId('outcome-lost')).toHaveAttribute('aria-checked', 'true')
    expect(screen.getByTestId('outcome-won')).toHaveAttribute('aria-checked', 'false')
  })
})
