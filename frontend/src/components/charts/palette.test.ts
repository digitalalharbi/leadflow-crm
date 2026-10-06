import { describe, expect, it } from 'vitest'
import { checkPalette, cvdDistance, normalDistance } from './paletteChecks'
import {
  DARK_HEXES, DARK_SURFACE, foldSeries, LIGHT_HEXES, LIGHT_SURFACE,
  MAX_SERIES, OTHER, SERIES, platformColor,
} from './palette'

/**
 * The palette is computed, so it is checked — every build.
 *
 * The version this replaced had eight series and failed three of these checks
 * from the day it was written: `#8b73f5` and `#4f7cff` sat next to each other
 * at ΔE 7.7 for normal vision and ΔE 1.1 for protanopia. Nothing caught it,
 * because nothing was looking. This is what looks.
 *
 * The maths runs against the hexes exported from `palette.ts`;
 * `scripts/check-chart-palette.mjs` separately fails the build if `index.css`
 * and that list ever disagree, so neither copy can drift unnoticed.
 */
describe('chart palette', () => {
  it('is two distinct five-colour sets', () => {
    expect(LIGHT_HEXES).toHaveLength(5)
    expect(DARK_HEXES).toHaveLength(5)
    // Dark is stepped against the dark surface, never flipped from light.
    expect([...DARK_HEXES]).not.toEqual([...LIGHT_HEXES])
  })

  it('every pair is separable on light', () => {
    // Contrast is a known, paid-for exception: one slot sits below 3:1 and is
    // allowed only because ChartCard ships labels and a table view.
    const issues = checkPalette([...LIGHT_HEXES], { mode: 'light', surface: LIGHT_SURFACE })
      .filter((issue) => issue.check !== 'contrast')

    expect(issues.map((i) => `${i.check}: ${i.detail}`)).toEqual([])
  })

  it('every pair is separable on dark', () => {
    const issues = checkPalette([...DARK_HEXES], { mode: 'dark', surface: DARK_SURFACE })
      .filter((issue) => issue.check !== 'contrast')

    expect(issues.map((i) => `${i.check}: ${i.detail}`)).toEqual([])
  })

  /** The specific defect that prompted all of this. */
  it('rejects the palette it replaced', () => {
    const old = ['#12a594', '#4f7cff', '#8b73f5', '#e0922f', '#16a34a', '#e85d75', '#3aa8e0', '#7c8698']
    const issues = checkPalette(old, { mode: 'light', surface: LIGHT_SURFACE })

    expect(issues.some((i) => i.check === 'cvd')).toBe(true)
    expect(issues.some((i) => i.check === 'normal-vision')).toBe(true)
    expect(normalDistance('#8b73f5', '#4f7cff')).toBeLessThan(10)
    expect(cvdDistance('#8b73f5', '#4f7cff')).toBeLessThan(3)
  })

  it('has no sixth series to reach for', () => {
    expect(SERIES).toHaveLength(MAX_SERIES)
    expect(SERIES).not.toContain(OTHER)
  })
})

describe('folding the tail', () => {
  const items = [
    { name: 'Meta', leads: 50 },
    { name: 'Google', leads: 40 },
    { name: 'TikTok', leads: 30 },
    { name: 'Snapchat', leads: 20 },
    { name: 'LinkedIn', leads: 10 },
    { name: 'Website', leads: 6 },
    { name: 'Walk-in', leads: 4 },
  ]

  const folded = foldSeries(items, {
    key: (i) => i.name,
    label: (i) => i.name,
    value: (i) => i.leads,
    otherLabel: 'Other',
  })

  it('keeps five and aggregates the rest into one real bucket', () => {
    expect(folded).toHaveLength(6)
    expect(folded.slice(0, 5).map((s) => s.label)).toEqual([
      'Meta', 'Google', 'TikTok', 'Snapchat', 'LinkedIn',
    ])
  })

  /** The tail is a sum, not a sample — a chart that drops it is a lying chart. */
  it('the aggregate carries the whole tail, and says what is in it', () => {
    const other = folded.at(-1)!

    expect(other.isOther).toBe(true)
    expect(other.value).toBe(10)
    expect(other.members.map((m) => m.name)).toEqual(['Website', 'Walk-in'])
  })

  it('the folded total equals the input total', () => {
    const total = items.reduce((sum, i) => sum + i.leads, 0)

    expect(folded.reduce((sum, s) => sum + s.value, 0)).toBe(total)
  })

  it('no two slices share a colour', () => {
    const colours = folded.map((s) => s.color)

    expect(new Set(colours).size).toBe(colours.length)
  })

  it('does not fold when everything fits', () => {
    const small = foldSeries(items.slice(0, 3), {
      key: (i) => i.name, label: (i) => i.name, value: (i) => i.leads,
    })

    expect(small).toHaveLength(3)
    expect(small.some((s) => s.isOther)).toBe(false)
  })
})

describe('mixing palettes', () => {
  /**
   * The bug this caught, on screen before it was measured: the platform chart
   * drew three measures from the categorical palette and borrowed
   * `--color-success` for the fourth, because green reads as "won". Nothing
   * had ever checked that green against the teal next to it — ΔE 13.2, under
   * the floor — so "qualified" and "won" were two bars a reader could not
   * reliably separate.
   */
  it('the success green is too close to the first series to be a fourth', () => {
    expect(normalDistance(LIGHT_HEXES[0], '#16a34a')).toBeLessThan(15)
  })

  it('but the first four series separate from each other', () => {
    const four = LIGHT_HEXES.slice(0, 4)
    const issues = checkPalette([...four], { mode: 'light', surface: LIGHT_SURFACE })
      .filter((issue) => issue.check !== 'contrast')

    expect(issues).toEqual([])
  })

  /** A status colour means a state. A series means an entity. Never swapped. */
  it('no status colour appears in the categorical palette', () => {
    const statuses = ['#12a594', '#16a34a', '#e0922f', '#e85d75', '#4f7cff']

    for (const status of statuses) {
      expect(LIGHT_HEXES).not.toContain(status)
      expect(DARK_HEXES).not.toContain(status)
    }
  })
})

describe('platform colours', () => {
  it('gives each paid platform its own series colour', () => {
    const paid = ['meta', 'google_ads', 'tiktok', 'snapchat', 'linkedin'].map(platformColor)

    expect(new Set(paid).size).toBe(5)
  })

  /** Sources that are not competing for budget are not competing for a hue. */
  it('puts non-paid sources on the neutral', () => {
    expect(platformColor('website')).toBe(OTHER)
    expect(platformColor('manual')).toBe(OTHER)
    expect(platformColor('something-new')).toBe(OTHER)
    expect(platformColor(null)).toBe(OTHER)
  })
})
