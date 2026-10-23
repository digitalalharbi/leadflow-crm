import type { CSSProperties } from 'react'
import type { FormDesign, PaletteToken } from '@/features/hub'

/**
 * A form's look, as the page draws it. A manager picks names from closed lists — a colour token, a radius, a spacing — and this maps
 * each to a value the page owns. Nothing a manager types ever becomes CSS, so a page that strangers open from an ad cannot be
 * given a stylesheet by anybody.
 */
export const PALETTE_HEX: Record<PaletteToken, string> = {
  emerald: '#0F766E', teal: '#0E7490', blue: '#1D4ED8', indigo: '#4338CA', violet: '#6D28D9', rose: '#BE123C', amber: '#B45309', slate: '#334155', stone: '#57534E',
}

const RADIUS = { none: '0px', sm: '6px', md: '10px', lg: '14px', xl: '22px' }
const GAP = { tight: '0.625rem', normal: '1rem', loose: '1.5rem' }
const SECTION_GAP = { tight: '1rem', normal: '1.75rem', loose: '2.5rem' }
const INPUT_H = { compact: '2.5rem', comfortable: '3rem', large: '3.5rem' }

/** Black or white, whichever reads on this colour (WCAG relative luminance). */
export function inkOn(hex: string): string {
  const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255).map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4))
  const luminance = 0.2126 * r + 0.7152 * g + 0.0722 * b

  return luminance > 0.4 ? '#0B1F1B' : '#FFFFFF'
}

export const contrast = (a: string, b: string): number => {
  const lum = (hex: string) => {
    const [r, g, bl] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255).map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4))

    return 0.2126 * r + 0.7152 * g + 0.0722 * bl
  }
  const [hi, lo] = [lum(a), lum(b)].sort((x, y) => y - x)

  return (hi + 0.05) / (lo + 0.05)
}

/** The custom properties a form's wrapper carries; everything inside reads them. */
export function designVars(design: FormDesign): CSSProperties {
  const s = design.style
  const primary = PALETTE_HEX[s.primary] ?? PALETTE_HEX.emerald
  const accent = PALETTE_HEX[s.accent] ?? PALETTE_HEX.amber
  const brandTheme = s.theme === 'brand'
  const neutral = s.theme === 'neutral'

  return {
    '--f-primary': primary,
    '--f-primary-ink': inkOn(primary),
    '--f-accent': accent,
    '--f-accent-ink': inkOn(accent),
    '--f-radius': RADIUS[s.radius] ?? RADIUS.lg,
    '--f-gap': GAP[s.field_spacing] ?? GAP.normal,
    '--f-section-gap': SECTION_GAP[s.section_spacing] ?? SECTION_GAP.normal,
    '--f-input-h': INPUT_H[s.input_size] ?? INPUT_H.comfortable,
    '--f-canvas': brandTheme ? `color-mix(in srgb, ${primary} 8%, #ffffff)` : neutral ? '#F4F4F5' : '#FFFFFF',
    '--f-surface': neutral ? '#FFFFFF' : '#FFFFFF',
    '--f-ink': '#0B1F1B',
    '--f-muted': '#4B5B57',
    '--f-border': neutral ? '#D4D4D8' : 'color-mix(in srgb, #0B1F1B 22%, #ffffff)',
  } as CSSProperties
}
