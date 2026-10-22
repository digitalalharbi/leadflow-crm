/// <reference types="node" />
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * The identity's colour pairs, measured against WCAG 2.2 AA.
 *
 * Text needs 4.5:1 (3:1 only for large text, 24px or 19px bold), and a graphic or the edge of a control
 * needs 3:1. The colours come straight from the brand kit's own file, so a change to the kit that makes a
 * pair unreadable fails here rather than on a customer's screen. The pairs are the ones the product
 * actually draws — each named for where it appears.
 */
const kit = readFileSync(resolve(process.cwd(), 'src/styles/mohtam-colors.css'), 'utf-8')
const color = new Map([...kit.matchAll(/^\s*--(mh-[a-z0-9-]+):\s*(#[0-9a-fA-F]{6});/gm)].map((m) => [m[1], m[2]]))

const hex = (name: string): string => {
  const value = color.get(name)

  if (!value) throw new Error(`--${name} is not in mohtam-colors.css`)

  return value
}

const channels = (h: string) => [1, 3, 5].map((i) => Number.parseInt(h.slice(i, i + 2), 16))
const mix = (a: string, share: number, b: string) =>
  `#${channels(a).map((v, i) => Math.round(v * share + channels(b)[i] * (1 - share)).toString(16).padStart(2, '0')).join('')}`

function luminance(h: string): number {
  const [r, g, b] = channels(h).map((v) => {
    const c = v / 255

    return c <= 0.040_45 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
  })

  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

function ratio(foreground: string, background: string): number {
  const [a, b] = [luminance(foreground), luminance(background)].sort((x, y) => y - x)

  return (a + 0.05) / (b + 0.05)
}

const white = hex('mh-white')
const limestone = hex('mh-limestone')
const stone = hex('mh-stone')
const ink = hex('mh-ink')
const slate = hex('mh-slate')
const pine900 = hex('mh-pine-900')
const pine800 = hex('mh-pine-800')
const emerald = hex('mh-green-700')
const green500 = hex('mh-green-500')
const green300 = hex('mh-green-300')
const green100 = hex('mh-green-100')
const brass700 = hex('mh-brass-700')
const brass500 = hex('mh-brass-500')
const brass400 = hex('mh-brass-400')
const brass100 = hex('mh-brass-100')

// What index.css derives from the kit (mirrored here, the same mix the stylesheet writes).
const inkSubtle = mix(slate, 0.94, white)
const brandTextDark = mix(green500, 0.38, limestone)
const infoDark = mix(hex('mh-info'), 0.62, limestone)
const dangerDark = mix(hex('mh-danger'), 0.5, limestone)
const inkSubtleDark = mix(green300, 0.9, pine800)

describe('text on the identity’s surfaces — AA, 4.5:1', () => {
  const text: Array<[string, string, string]> = [
    ['body text on a white card', ink, white],
    ['body text on the limestone page', ink, limestone],
    ['body text on a stone section', ink, stone],
    ['secondary text on a white card', slate, white],
    ['secondary text on the limestone page', slate, limestone],
    ['secondary text on a stone section', slate, stone],
    ['the lightest secondary text on a white card', inkSubtle, white],
    ['the lightest secondary text on the limestone page', inkSubtle, limestone],
    ['a link or active tab (emerald) on white', emerald, white],
    ['a link or active tab (emerald) on limestone', emerald, limestone],
    ['the primary button label', white, emerald],
    ['the primary button label on hover', white, pine800],
    ['copper text (dark copper) on white', brass700, white],
    ['copper text (dark copper) on limestone', brass700, limestone],
    ['a percentage change (dark copper on soft copper)', brass700, brass100],
    ['a healthy badge (emerald on soft green)', emerald, green100],
    ['a warning badge (dark copper on soft copper)', brass700, brass100],
    ['danger text on white', hex('mh-danger'), white],
    ['danger text on limestone', hex('mh-danger'), limestone],
    ['info text on white', hex('mh-info'), white],
    ['the danger button label', white, hex('mh-danger')],
    ['the sidebar item text (soft green) on pine', green300, pine900],
    ['the sidebar active item', white, emerald],
    ['text on the pine surfaces (limestone)', limestone, pine900],
    ['text on the second pine (rows of the attention card)', limestone, pine800],
    ['the attention number (light copper) on pine', brass400, pine900],
    ['secondary text on pine (soft green)', green300, pine800],
  ]

  it.each(text)('%s', (_where, foreground, background) => {
    expect(ratio(foreground, background)).toBeGreaterThanOrEqual(4.5)
  })
})

describe('text in the dark theme — AA, 4.5:1', () => {
  const text: Array<[string, string, string]> = [
    ['body text on a card', limestone, pine800],
    ['body text on the page', limestone, pine900],
    ['secondary text on a card', green300, pine800],
    ['the lightest secondary text on a card', inkSubtleDark, pine800],
    ['brand as text on a card', brandTextDark, pine800],
    ['the primary button label (white on the lifted emerald)', white, green500],
    ['copper text on a card', brass400, pine800],
    ['info on a card', infoDark, pine800],
    ['danger on a card', dangerDark, pine800],
  ]

  it.each(text)('%s', (_where, foreground, background) => {
    expect(ratio(foreground, background)).toBeGreaterThanOrEqual(4.5)
  })
})

describe('graphics and controls — 3:1', () => {
  const graphics: Array<[string, string, string]> = [
    ['the focus ring (emerald) on the limestone page', emerald, limestone],
    ['the focus ring (emerald) on a white card', emerald, white],
    ['the active tab marker on white', emerald, white],
    ['copper bright (a rule, a large figure) on white', brass500, white],
    ['the logo ring on a light surface', emerald, limestone],
    ['the lifted brand on a dark card (a focus ring or an icon)', brandTextDark, pine800],
  ]

  it.each(graphics)('%s', (_where, foreground, background) => {
    expect(ratio(foreground, background)).toBeGreaterThanOrEqual(3)
  })
})

describe('where the identity must not be used', () => {
  /** The kit's own rule — recorded so nobody "fixes" it by using copper for a small label. */
  it('bright copper is not a text colour on white: it is for rules, dots and large figures', () => {
    expect(ratio(brass500, white)).toBeLessThan(4.5)
    expect(ratio(brass500, white)).toBeGreaterThanOrEqual(3)
  })

  it('white on bright copper fails for small text, so a copper fill takes dark text or no text', () => {
    expect(ratio(white, brass500)).toBeLessThan(4.5)
  })
})
