import { useEffect } from 'react'
import type { Branding } from '@/types/api'

/**
 * Pushes the tenant's colours into CSS custom properties. Every component reads
 * `var(--brand)`, so one assignment here rebrands the entire application —
 * no rebuild, no per-customer bundle.
 */
export function useBrandingTheme(branding: Branding | null) {
  useEffect(() => {
    // No branding yet: leave the CSS defaults in place rather than overwriting
    // colours another component may already have resolved.
    if (!branding) return

    const root = document.documentElement
    const primary = branding.primary_color
    const secondary = branding.secondary_color

    root.style.setProperty('--brand', primary)
    root.style.setProperty('--brand-secondary', secondary)
    root.style.setProperty('--brand-soft', `color-mix(in srgb, ${primary} 10%, white)`)
    root.style.setProperty('--brand-ring', `color-mix(in srgb, ${primary} 35%, transparent)`)

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
  }, [branding])
}
