import { useEffect } from 'react'
import { useUi } from '@/stores/ui'
import type { Branding } from '@/types/api'

/**
 * Pushes the tenant's colours into CSS custom properties. Every component reads
 * `var(--brand)`, so one assignment here rebrands the entire application —
 * no rebuild, no per-customer bundle.
 */
const PLATFORM_NAME = 'مهتم'
const PLATFORM_FAVICON = '/favicon.svg'
const PLATFORM_MANIFEST = '/site.webmanifest'

/** The blob the last tenant's manifest lives in, so it can be released. */
let manifestUrl: string | null = null

function setIcon(selector: string, href: string) {
  let link = document.querySelector<HTMLLinkElement>(selector)

  if (!link) {
    link = document.createElement('link')
    link.rel = 'icon'
    document.head.appendChild(link)
  }

  link.href = href
}

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

    // The browser's own chrome on a phone, so a tenant's app reaches the
    // status bar rather than stopping at the viewport.
    document
      .querySelector<HTMLMetaElement>("meta[name='theme-color']")
      ?.setAttribute('content', primary)

    document.title = branding.system_name || PLATFORM_NAME

    /*
     * Written on every branding change, not only when the tenant has one of
     * their own: signing out of a branded tenant and into an unbranded one
     * would otherwise leave the previous customer's icon and title in the tab,
     * which is the one white-label leak a user can see without looking.
     */
    setIcon("link[rel='icon']", branding.favicon_url || PLATFORM_FAVICON)
    setManifest(branding)
  }, [branding, appearance])
}

/**
 * The installed app's own name and colour.
 *
 * A manifest is the one branding surface that outlives the tab: a customer who
 * adds this to a home screen keeps whatever it said at that moment. Shipping
 * only the static file would put مهتم on their phone, which is the same leak
 * the title and the favicon are already careful about — so it is rebuilt from
 * the tenant's branding and swapped in, and a tenant with none of their own
 * falls back to the file.
 */
function setManifest(branding: Branding): void {
  const link = document.querySelector<HTMLLinkElement>("link[rel='manifest']")

  if (!link) {
    return
  }

  const name = branding.system_name || PLATFORM_NAME

  const manifest = {
    name,
    short_name: name,
    lang: branding.locale || 'ar',
    dir: (branding.locale || 'ar') === 'ar' ? 'rtl' : 'ltr',
    start_url: '/',
    scope: '/',
    display: 'standalone',
    background_color: '#ffffff',
    theme_color: branding.primary_color,
    icons: [
      {
        src: branding.favicon_url || PLATFORM_FAVICON,
        sizes: 'any',
        type: (branding.favicon_url ?? PLATFORM_FAVICON).endsWith('.svg')
          ? 'image/svg+xml'
          : 'image/png',
        purpose: 'any',
      },
    ],
  }

  try {
    const next = URL.createObjectURL(
      new Blob([JSON.stringify(manifest)], { type: 'application/manifest+json' }),
    )

    link.href = next

    // Signing in and out of several tenants in one session would otherwise
    // leak a blob per tenant for as long as the page is open.
    if (manifestUrl) {
      URL.revokeObjectURL(manifestUrl)
    }

    manifestUrl = next
  } catch {
    // A browser that will not make a blob URL keeps the static file, which is
    // the platform default — worse than the tenant's own name, better than a
    // page that throws while painting the chrome.
    link.href = PLATFORM_MANIFEST
  }
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
