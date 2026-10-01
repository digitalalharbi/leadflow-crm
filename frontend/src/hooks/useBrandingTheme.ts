import { useEffect } from 'react'
import { useUi } from '@/stores/ui'
import type { Branding } from '@/types/api'

/**
 * Pushes the tenant's colours into CSS custom properties. Every component reads
 * `var(--brand)`, so one assignment here rebrands the entire application —
 * no rebuild, no per-customer bundle.
 */
export function useBrandingTheme(branding: Branding | null) {
  // Re-applied when the theme flips, because the soft tints are mixed against
  // the page colour and that colour has just changed.
  const appearance = useUi((s) => s.appearance)

  useEffect(() => {
    // No branding yet: leave the CSS defaults in place rather than overwriting
    // colours another component may already have resolved.
    if (!branding) return

    const root = document.documentElement
    const primary = branding.primary_color
    const secondary = branding.secondary_color

    const dark = root.classList.contains('dark')

    root.style.setProperty('--brand', primary)
    root.style.setProperty('--brand-secondary', secondary)
    // Mixed toward the page, not toward white: a 10%-on-white tint is invisible
    // on a dark surface, and the active nav item would disappear with it.
    root.style.setProperty(
      '--brand-soft',
      `color-mix(in srgb, ${primary} ${dark ? '22%' : '10%'}, var(--color-surface))`,
    )
    root.style.setProperty(
      '--brand-softer',
      `color-mix(in srgb, ${primary} ${dark ? '12%' : '5%'}, var(--color-surface))`,
    )
    root.style.setProperty('--brand-ring', `color-mix(in srgb, ${primary} 28%, transparent)`)
    // White text on a pale brand colour is unreadable, so the contrast colour
    // is chosen from the brand's own luminance rather than assumed.
    root.style.setProperty('--brand-contrast', readableOn(primary))

    if (branding.system_name) {
      document.title = branding.system_name
    }

    if (branding.favicon_url) {
      let link = document.querySelector<HTMLLinkElement>("link[rel='icon']")

      if (!link) {
        link = document.createElement('link')
        link.rel = 'icon'
        document.head.appendChild(link)
      }

      link.href = branding.favicon_url
    }
  }, [branding, appearance])
}

/**
 * Black or white, whichever a reader can actually see on this colour.
 * Relative luminance per WCAG, which is cheap and good enough for a fill.
 */
function readableOn(hex: string): string {
  const value = hex.replace('#', '')

  if (value.length !== 6) return '#ffffff'

  const channel = (start: number) => {
    const c = Number.parseInt(value.slice(start, start + 2), 16) / 255

    return c <= 0.040_45 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
  }

  const luminance = 0.2126 * channel(0) + 0.7152 * channel(2) + 0.0722 * channel(4)

  return luminance > 0.45 ? '#16181f' : '#ffffff'
}
