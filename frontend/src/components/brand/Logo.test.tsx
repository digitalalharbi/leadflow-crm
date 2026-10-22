/// <reference types="node" />
import { readdirSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { Logo, type LogoLayout, type LogoVariant } from './Logo'

/**
 * The logo is the kit's, never a copy of it: the SVGs the app ships must be byte-identical to
 * `brand/Mohtam-Brand-Kit`, and the component only chooses which one to show.
 */
const kitRoot = resolve(process.cwd(), '../brand/Mohtam-Brand-Kit')
const kitLogoDir = resolve(kitRoot, readdirSync(kitRoot).find((name) => name.startsWith('01-'))!, 'SVG')
const shipped = resolve(process.cwd(), 'src/assets/brand/logo')

const FOLDERS = ['primary', 'reverse', 'on-green', 'mono-black', 'mono-white']
const LAYOUTS: LogoLayout[] = ['horizontal', 'stacked', 'mark']

describe('the shipped logo files', () => {
  it.each(FOLDERS.flatMap((folder) => LAYOUTS.map((layout) => [folder, layout] as const)))('%s / %s is the kit’s file, unmodified', (folder, layout) => {
    const name = `mohtam-${layout}-${folder}.svg`

    expect(readFileSync(resolve(shipped, folder, name), 'utf-8')).toBe(readFileSync(resolve(kitLogoDir, folder, name), 'utf-8'))
  })

  it('carries the kit’s colours and no others (emerald, pine, copper, limestone, white, ink)', () => {
    const allowed = new Set(['#1D5E52', '#0C2A25', '#B38A55', '#C9A26E', '#F6F3EE', '#FFFFFF', '#14201D', '#E3C99F', '#133B34'])

    for (const folder of FOLDERS) {
      for (const layout of LAYOUTS) {
        const svg = readFileSync(resolve(shipped, folder, `mohtam-${layout}-${folder}.svg`), 'utf-8')

        for (const [hex] of svg.matchAll(/#[0-9A-Fa-f]{6}\b/g)) expect(allowed.has(hex.toUpperCase()), `${folder}/${layout} ${hex}`).toBe(true)
      }
    }
  })
})

describe('Logo', () => {
  it.each(['primary', 'reverse', 'on-green', 'mono', 'mono-white'] as LogoVariant[])('draws the %s horizontal logo with the product’s name for a screen reader', (variant) => {
    const { container } = render(<Logo variant={variant} />)

    expect(screen.getByRole('img', { name: 'مهتم' })).toBeInTheDocument()
    expect(container.querySelector('svg')).not.toBeNull()
    // The name is in the markup too, so anything that reads text finds it — it is a drawing, not a text node set in a font.
    expect(container.textContent).toContain('مهتم')
    expect(container.querySelector('svg text')).toBeNull()
  })

  it('draws the reverse logo in the light ink of the kit and the primary in emerald, pine and copper', () => {
    const reverse = render(<Logo variant="reverse" layout="mark" />).container.innerHTML
    const primary = render(<Logo variant="primary" layout="mark" />).container.innerHTML

    expect(reverse).toContain('#F6F3EE')
    expect(reverse).toContain('#C9A26E')
    expect(primary).toContain('#1D5E52')
    expect(primary).toContain('#B38A55')
  })

  it('never draws below the kit’s smallest sizes: 96px wide horizontal, 20px mark', () => {
    const horizontal = render(<Logo layout="horizontal" height={10} />).getAllByTestId('logo')[0]
    const mark = render(<Logo layout="mark" height={4} />).getAllByTestId('logo')[0]

    expect(Number.parseFloat(horizontal.firstElementChild!.nextElementSibling!.getAttribute('style')!.match(/width:\s*([\d.]+)px/)![1])).toBeGreaterThanOrEqual(96)
    expect(Number.parseFloat(mark.firstElementChild!.nextElementSibling!.getAttribute('style')!.match(/height:\s*([\d.]+)px/)![1])).toBeGreaterThanOrEqual(20)
  })

  it('on an automatic surface carries both, one per theme, so it follows the dark theme without a flash', () => {
    const { container } = render(<Logo variant="auto" />)

    expect(container.querySelector('[data-variant="primary"]')?.className).toContain('dark:hidden')
    expect(container.querySelector('[data-variant="reverse"]')?.className).toContain('dark:inline-block')
  })
})
