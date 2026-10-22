/// <reference types="node" />
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { SalesActionBar } from './SalesActionBar'
import { renderWithProviders } from '@/test/utils'

function bar(props: Partial<React.ComponentProps<typeof SalesActionBar>> = {}) {
  const handlers = { call: vi.fn(), whatsapp: vi.fn(), outcome: vi.fn(), next: vi.fn() }

  renderWithProviders(
    <SalesActionBar
      whatsapp={{ href: 'https://wa.me/966500300021', external: true, onClick: handlers.whatsapp }}
      call={{ href: 'tel:+966500300021', onClick: handlers.call }}
      onOutcome={handlers.outcome}
      onNext={handlers.next}
      {...props}
    />,
  )

  return handlers
}

describe('SalesActionBar', () => {
  it('has WhatsApp, Call, Outcome and Next, each labelled in words and 56px tall or more', () => {
    bar()

    for (const id of ['sales-whatsapp', 'sales-call', 'sales-outcome', 'sales-next']) {
      expect(screen.getByTestId(id).className).toContain('min-h-14')
      expect((screen.getByTestId(id).textContent ?? '').trim().length).toBeGreaterThan(0)
    }
    expect(screen.getByRole('navigation')).toBeInTheDocument()
  })

  it('Call is a real link to the dialer, so a press opens it on every phone', () => {
    bar()

    expect(screen.getByTestId('sales-call')).toHaveAttribute('href', 'tel:+966500300021')
  })

  it('WhatsApp opens WhatsApp by its deep link, in a new window, safely', () => {
    bar()

    const link = screen.getByTestId('sales-whatsapp')

    expect(link).toHaveAttribute('href', 'https://wa.me/966500300021')
    expect(link).toHaveAttribute('target', '_blank')
    expect(link).toHaveAttribute('rel', 'noreferrer')
  })

  it('runs the handlers it was given', () => {
    const handlers = bar()

    fireEvent.click(screen.getByTestId('sales-call'))
    fireEvent.click(screen.getByTestId('sales-outcome'))
    fireEvent.click(screen.getByTestId('sales-next'))

    expect(handlers.call).toHaveBeenCalledTimes(1)
    expect(handlers.outcome).toHaveBeenCalledTimes(1)
    expect(handlers.next).toHaveBeenCalledTimes(1)
  })

  it('a number that cannot be dialled is not a link, cannot be pressed, and the reason is written above the bar', () => {
    bar({ call: { disabled: true }, problem: 'رقم الهاتف غير صالح للاتصال: abc' })

    const call = screen.getByTestId('sales-call')

    expect(call).not.toHaveAttribute('href')
    expect(call).toBeDisabled()
    expect(screen.getByTestId('sales-problem')).toHaveTextContent('غير صالح')
  })
})

describe('the bar’s safe area', () => {
  const css = readFileSync(resolve(process.cwd(), 'src/index.css'), 'utf-8')

  it('is padded by the device’s bottom inset, so in a home-screen app it never hides behind the system bar', () => {
    const rule = css.match(/\.sales-bar\s*\{[^}]*\}/)?.[0] ?? ''

    expect(rule).toContain('env(safe-area-inset-bottom)')
  })

  it('keeps the page clear of the bar, plus the same inset, so the last line is never behind it', () => {
    const rule = css.match(/\.sales-pad\s*\{[^}]*\}/)?.[0] ?? ''

    expect(rule).toContain('env(safe-area-inset-bottom)')
  })

  it('also clears the inset at the top, for the notch, on the screens that have no app bar', () => {
    const lead = readFileSync(resolve(process.cwd(), 'src/components/mobile/MobileLeadScreen.tsx'), 'utf-8')
    const work = readFileSync(resolve(process.cwd(), 'src/components/mobile/MobileWork.tsx'), 'utf-8')

    expect(lead).toContain('env(safe-area-inset-top)')
    expect(work).toContain('env(safe-area-inset-top)')
  })
})
