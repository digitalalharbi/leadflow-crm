import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
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
const kitCss = readFileSync(resolve(process.cwd(), 'src/styles/mohtam-colors.css'), 'utf-8')

describe('chart palette', () => {
  it('is two distinct five-colour sets', () => {
    expect(LIGHT_HEXES).toHaveLength(5)
    expect(DARK_HEXES).toHaveLength(5)
    // Dark is stepped against the dark surface, never flipped from light.
    expect([...DARK_HEXES]).not.toEqual([...LIGHT_HEXES])
  })

  /** The identity decides the colours; this fails if the list ever stops being the kit's own, in its order. */
  it('is the brand kit’s palette, in the kit’s order', () => {
    expect([...LIGHT_HEXES]).toEqual(['#1D5E52', '#B38A55', '#3A6F95', '#8DB9AE', '#E3C99F'])
    // Emerald, copper, info blue, soft green, light copper — straight from mohtam-colors.css.
    expect(kitColour('--mh-green-700')).toBe(LIGHT_HEXES[0])
    expect(kitColour('--mh-brass-500')).toBe(LIGHT_HEXES[1])
    expect(kitColour('--mh-info')).toBe(LIGHT_HEXES[2])
    expect(kitColour('--mh-green-300')).toBe(LIGHT_HEXES[3])
    expect(kitColour('--mh-brass-200')).toBe(LIGHT_HEXES[4])
    expect(LIGHT_SURFACE).toBe('#ffffff')
    // The dark card is the kit's ink lifted 10% toward limestone (index.css `.dark --color-surface`): charcoal, not pine.
    expect(DARK_SURFACE).toBe('#2B3532')
  })

  /*
   * The property that decides whether a reader can tell two series apart at all: no pair collapses
   * under simulated colour blindness. It held for the palette this replaced and it holds for the
   * kit's, in both themes, with nothing excused.
   */
  it('keeps every pair separable under colour blindness, in both themes', () => {
    for (const [hexes, mode, surface] of [[LIGHT_HEXES, 'light', LIGHT_SURFACE], [DARK_HEXES, 'dark', DARK_SURFACE]] as const) {
      const cvd = checkPalette([...hexes], { mode, surface }).filter((issue) => issue.check === 'cvd')

      expect(cvd.map((i) => i.detail), mode).toEqual([])
    }
  })

  /*
   * What the kit's palette does not clear, written down.
   *
   * The identity is an earthy, quiet set and the earlier palette's stricter bar (full-colour pair
   * distance 15, a chroma floor, a lightness band, 3:1 contrast on every slot) is not one it meets.
   * That was a deliberate trade by the owner of the identity; these two lists are what it costs. A
   * new miss, or a worse one, fails here instead of joining them unseen. The charts make it good
   * with the second encoding they already carry: legend, direct labels, 2px gaps and a table view.
   */
  it('misses exactly the checks recorded here on light, and no others', () => {
    const issues = checkPalette([...LIGHT_HEXES], { mode: 'light', surface: LIGHT_SURFACE })

    expect(summarise(issues)).toEqual({
      chroma: ['#1D5E52', '#3A6F95', '#8DB9AE', '#B38A55', '#E3C99F'],
      contrast: ['#8DB9AE', '#E3C99F'],
      lightness: ['#E3C99F'],
      'normal-vision': ['#1D5E52↔#3A6F95', '#8DB9AE↔#E3C99F', '#B38A55↔#8DB9AE'],
    })
  })

  it('misses exactly the checks recorded here on dark, and no others', () => {
    const issues = checkPalette([...DARK_HEXES], { mode: 'dark', surface: DARK_SURFACE })

    expect(summarise(issues)).toEqual({
      chroma: ['#2E7D6E', '#6990AB', '#8DB9AE', '#C9A26E', '#E3C99F'],
      contrast: ['#2E7D6E'],
      lightness: ['#8DB9AE', '#C9A26E', '#E3C99F'],
      'normal-vision': ['#6990AB↔#2E7D6E', '#8DB9AE↔#6990AB', '#8DB9AE↔#C9A26E', '#8DB9AE↔#E3C99F', '#C9A26E↔#E3C99F'],
    })
  })

  /** The closest any two series sit, so a nudge that makes it worse is caught even when no check changes. */
  it('keeps the closest pairs no closer than they were when the kit palette was adopted', () => {
    for (const [hexes, normal, cvd] of [[LIGHT_HEXES, 11, 8], [DARK_HEXES, 10, 8]] as const) {
      let closestNormal = Infinity
      let closestCvd = Infinity

      for (let a = 0; a < hexes.length; a++) {
        for (let b = a + 1; b < hexes.length; b++) {
          closestNormal = Math.min(closestNormal, normalDistance(hexes[a], hexes[b]))
          closestCvd = Math.min(closestCvd, cvdDistance(hexes[a], hexes[b]))
        }
      }

      expect(closestNormal).toBeGreaterThanOrEqual(normal)
      expect(closestCvd).toBeGreaterThanOrEqual(cvd)
    }
  })

  /** The checker itself is still trusted: it rejects the palette that originally prompted it. */
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

/** The hex a kit variable is declared with, read from the copy of the kit's colour file the app ships. */
function kitColour(name: string): string {
  const match = new RegExp(`${name}:\\s*(#[0-9A-Fa-f]{6});`).exec(kitCss)

  if (!match) throw new Error(`${name} is not in mohtam-colors.css`)

  return match[1].toUpperCase()
}

/** Issues grouped by check, each reduced to the colour (or pair) it names, in a stable order. */
function summarise(issues: ReturnType<typeof checkPalette>): Record<string, string[]> {
  const out: Record<string, string[]> = {}

  for (const issue of issues) {
    const key = issue.check === 'normal-vision'
      ? issue.detail.split(' ΔE')[0].replace(/ ↔ /, '↔')
      : issue.detail.split(' ')[0]

    ;(out[issue.check] ??= []).push(key)
  }

  for (const list of Object.values(out)) list.sort()

  return out
}

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
    expect(normalDistance(LIGHT_HEXES[0], '#2F7D5B')).toBeLessThan(15)
  })

  it('but the first four series separate from each other for a colour-blind reader', () => {
    const four = LIGHT_HEXES.slice(0, 4)
    const issues = checkPalette([...four], { mode: 'light', surface: LIGHT_SURFACE })
      .filter((issue) => issue.check === 'cvd')

    expect(issues).toEqual([])
  })

  /**
   * A status colour means a state. A series means an entity. Never swapped — with the one exception
   * the kit itself makes: its info blue is also the third series, so the three that carry a verdict
   * (healthy, warning, danger) stay out of the palette and info is shared.
   */
  it('no verdict colour appears in the categorical palette', () => {
    const verdicts = ['#2F7D5B', '#C08A2E', '#9E3B30']

    for (const status of verdicts) {
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
