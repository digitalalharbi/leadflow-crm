/**
 * The colour maths behind the chart palette, so CI can re-check it.
 *
 * A palette validated once is a palette that drifts: somebody adds a sixth
 * series, nudges a hue to match a slide, and nothing anywhere objects — the
 * chart still renders, it is merely unreadable for a tenth of the people
 * looking at it. The previous palette failed three of these checks and had done
 * since it was written.
 *
 * Nothing here is eyeballed. Colour-vision simulation uses Machado, Oliveira &
 * Fernandes (2009) at full severity, which is the standard model; distances are
 * Euclidean in OKLab ×100.
 */

export const BAND = { light: [0.43, 0.77], dark: [0.48, 0.67] } as const
export const CHROMA_FLOOR = 0.1
/** Below this, a pair is indistinguishable to colour-blind readers. */
export const CVD_FLOOR = 6
/** Below this, a pair is hard to tell apart with *full* colour vision. */
export const NORMAL_FLOOR = 15
export const CONTRAST_MIN = 3

type RGB = [number, number, number]

const toLinear = (c: number) => (c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4)

/** The inverse. The simulation works in linear light; everything else expects sRGB. */
const toSrgb = (c: number) => (c <= 0.0031308 ? 12.92 * c : 1.055 * c ** (1 / 2.4) - 0.055)

export function hexToRgb(hex: string): RGB {
  const h = hex.trim().replace(/^#/, '')

  if (!/^[0-9a-fA-F]{6}$/.test(h)) {
    throw new Error(`Not a 6-digit hex colour: ${hex}`)
  }

  return [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16) / 255) as RGB
}

export function rgbToOklab([r, g, b]: RGB): RGB {
  const [lr, lg, lb] = [r, g, b].map(toLinear)
  const l = Math.cbrt(0.4122214708 * lr + 0.5363325363 * lg + 0.0514459929 * lb)
  const m = Math.cbrt(0.2119034982 * lr + 0.6806995451 * lg + 0.1073969566 * lb)
  const s = Math.cbrt(0.0883024619 * lr + 0.2817188376 * lg + 0.6299787005 * lb)

  return [
    0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s,
    1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s,
    0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s,
  ]
}

export function lightness(hex: string): number {
  return rgbToOklab(hexToRgb(hex))[0]
}

export function chroma(hex: string): number {
  const [, a, b] = rgbToOklab(hexToRgb(hex))

  return Math.hypot(a, b)
}

/** Machado, Oliveira & Fernandes (2009), severity 1.0, in linear RGB. */
const MACHADO: Record<string, number[][]> = {
  protan: [
    [0.152286, 1.052583, -0.204868],
    [0.114503, 0.786281, 0.099216],
    [-0.003882, -0.048116, 1.051998],
  ],
  deutan: [
    [0.367322, 0.860646, -0.227968],
    [0.280085, 0.672501, 0.047413],
    [-0.01182, 0.04294, 0.968881],
  ],
}

function simulate(rgb: RGB, kind: 'protan' | 'deutan'): RGB {
  const lin = rgb.map(toLinear)
  const m = MACHADO[kind]

  /*
   * Encoded back to sRGB before it leaves. The matrix operates on linear light,
   * and everything downstream linearises what it is given — returning linear
   * values here means they get linearised twice, which quietly shrinks every
   * distance and reports pairs as closer than they are. It read a perfectly
   * separable teal and vermillion as ΔE 4.6.
   */
  return m.map((row) =>
    toSrgb(Math.min(1, Math.max(0, row[0] * lin[0] + row[1] * lin[1] + row[2] * lin[2]))),
  ) as RGB
}

function distance(a: RGB, b: RGB): number {
  const [l1, a1, b1] = rgbToOklab(a)
  const [l2, a2, b2] = rgbToOklab(b)

  return Math.hypot(l1 - l2, a1 - a2, b1 - b2) * 100
}

/** The worse of protan and deutan: a palette is only as safe as its weakest. */
export function cvdDistance(hexA: string, hexB: string): number {
  const a = hexToRgb(hexA)
  const b = hexToRgb(hexB)

  return Math.min(
    distance(simulate(a, 'protan'), simulate(b, 'protan')),
    distance(simulate(a, 'deutan'), simulate(b, 'deutan')),
  )
}

export function normalDistance(hexA: string, hexB: string): number {
  return distance(hexToRgb(hexA), hexToRgb(hexB))
}

function relativeLuminance(rgb: RGB): number {
  const [r, g, b] = rgb.map(toLinear)

  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

export function contrast(hex: string, surface: string): number {
  const a = relativeLuminance(hexToRgb(hex))
  const b = relativeLuminance(hexToRgb(surface))

  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05)
}

export interface PaletteIssue {
  check: string
  detail: string
}

/**
 * Every pair, not just adjacent ones.
 *
 * Adjacent-only is the weaker test and it is the one that lets a palette ship
 * with two colours a reader cannot separate — they simply have to land in the
 * same chart, which is not a thing the palette gets to decide.
 */
export function checkPalette(
  palette: string[],
  { mode, surface }: { mode: 'light' | 'dark'; surface: string },
): PaletteIssue[] {
  const issues: PaletteIssue[] = []
  const [min, max] = BAND[mode]

  for (const hex of palette) {
    const l = lightness(hex)

    if (l < min || l > max) {
      issues.push({ check: 'lightness', detail: `${hex} L ${l.toFixed(3)} outside ${min}–${max}` })
    }

    if (chroma(hex) < CHROMA_FLOOR) {
      issues.push({ check: 'chroma', detail: `${hex} reads grey (C ${chroma(hex).toFixed(3)})` })
    }

    if (contrast(hex, surface) < CONTRAST_MIN) {
      // A warning in the reference validator, and an obligation here: it is
      // only allowed because ChartCard ships a table view and direct labels.
      issues.push({
        check: 'contrast',
        detail: `${hex} ${contrast(hex, surface).toFixed(2)}:1 vs ${surface} — needs labels or a table`,
      })
    }
  }

  for (let i = 0; i < palette.length; i++) {
    for (let j = i + 1; j < palette.length; j++) {
      const cvd = cvdDistance(palette[i], palette[j])
      const normal = normalDistance(palette[i], palette[j])

      if (cvd < CVD_FLOOR) {
        issues.push({
          check: 'cvd',
          detail: `${palette[i]} ↔ ${palette[j]} ΔE ${cvd.toFixed(1)} — one colour to a colour-blind reader`,
        })
      }

      if (normal < NORMAL_FLOOR) {
        issues.push({
          check: 'normal-vision',
          detail: `${palette[i]} ↔ ${palette[j]} ΔE ${normal.toFixed(1)} — hard to tell apart even with full colour vision`,
        })
      }
    }
  }

  return issues
}
