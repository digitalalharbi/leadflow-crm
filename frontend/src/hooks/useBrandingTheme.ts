import { useEffect } from 'react'
import { useUi } from '@/stores/ui'
import type { Branding } from '@/types/api'

/**
 * Pushes the tenant's colours into CSS custom properties. Every component reads
 * `var(--brand)`, so one assignment here rebrands the entire application —
 * no rebuild, no per-customer bundle.
 */
const PLATFORM_NAME = 'مهتم'
/** What the tab says when the workspace has not renamed the product. */
export const PLATFORM_TITLE = 'مهتم | حوّل الاهتمام إلى فرصة'
const PLATFORM_FAVICON = '/favicon.svg'
const PLATFORM_MANIFEST = '/site.webmanifest'
/** The browser chrome on a phone, and the installed app's splash: pine and limestone, from the kit. */
const PLATFORM_THEME_COLOR = '#0C2A25'
const PLATFORM_BACKGROUND = '#F6F3EE'

/**
 * What the platform stores for a workspace that never chose a colour (the migration's defaults),
 * and the identity's own emerald. Either means "no colours of their own": the platform's tokens
 * apply as the stylesheet defines them, rather than a blue nobody picked.
 */
const NO_CHOICE = new Set(['#2563eb', '#1d5e52'])

/** Every property a workspace's own colours can set, so switching back to the platform's can clear them all. */
const TENANT_PROPERTIES = [
  '--brand', '--brand-hover', '--brand-secondary', '--brand-soft', '--brand-softer', '--brand-ring',
  '--brand-contrast', '--brand-tint', '--brand-bright', '--brand-deep',
  '--chrome-1', '--chrome-2', '--chrome-active', '--chrome-active-ink', '--chrome-muted',
  '--accent', '--accent-text', '--accent-soft', '--chart-seq-old', '--chart-seq-mid', '--chart-seq-latest',
] as const

/** Whether a workspace chose colours of its own — if not, it is shown with the platform's identity, logo included. */
export function usesOwnColours(primary: string | null | undefined): boolean {
  return Boolean(primary) && !NO_CHOICE.has(String(primary).toLowerCase())
}

function hasOwnColours(branding: Branding): boolean {
  return usesOwnColours(branding.primary_color)
}

/** The blob the last tenant's manifest lives in, so it can be released. */
let manifestUrl: string | null = null

/** The platform's own icon links as the page shipped them (the kit's: .ico, .svg and the PNG sizes), read once before any workspace changes them. */
let platformIcons: Array<{ href: string; type: string | null; sizes: string | null }> | null = null

/**
 * The tab's icon: the workspace's own when it has one, otherwise the platform's whole set.
 *
 * The page carries several `rel="icon"` links (a browser picks whichever suits it), so swapping only the
 * first would leave the platform's SVG and PNGs in place beside a customer's icon — and some browsers would
 * show ours. All of them are replaced, and the full set is put back for a workspace with none of its own.
 */
function setIcons(own: string | null) {
  const links = Array.from(document.querySelectorAll<HTMLLinkElement>("link[rel='icon']"))

  platformIcons ??= links.length > 0
    ? links.map((link) => ({ href: link.getAttribute('href') ?? PLATFORM_FAVICON, type: link.getAttribute('type'), sizes: link.getAttribute('sizes') }))
    : [{ href: PLATFORM_FAVICON, type: 'image/svg+xml', sizes: null }]

  for (const link of links) link.remove()

  for (const icon of own ? [{ href: own, type: null, sizes: null }] : platformIcons) {
    const link = document.createElement('link')

    link.rel = 'icon'
    link.setAttribute('href', icon.href)
    if (icon.type) link.setAttribute('type', icon.type)
    if (icon.sizes) link.setAttribute('sizes', icon.sizes)
    document.head.appendChild(link)
  }
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
    const own = hasOwnColours(branding)
    const primary = own ? branding.primary_color : PLATFORM_THEME_COLOR
    const secondary = branding.secondary_color

    const dark = root.classList.contains('dark')

    if (own) {
      const mix = (colour: string, percent: number, other: string) => `color-mix(in srgb, ${colour} ${percent}%, ${other})`
      const set = (name: string, value: string) => root.style.setProperty(name, value)

      set('--brand', primary)
      set('--brand-hover', mix(primary, 82, 'black'))
      set('--brand-secondary', secondary)
      // Mixed toward the page, not toward white: a 10%-on-white tint is invisible
      // on a dark surface, and the active nav item would disappear with it.
      set('--brand-soft', mix(primary, dark ? 22 : 10, 'var(--color-surface)'))
      set('--brand-softer', mix(primary, dark ? 12 : 5, 'var(--color-surface)'))
      set('--brand-ring', mix(primary, 28, 'transparent'))
      // White text on a pale brand colour is unreadable, so the contrast colour
      // is chosen from the brand's own luminance rather than assumed.
      set('--brand-contrast', readableOn(primary))
      set('--brand-tint', mix(primary, 42, 'white'))
      set('--brand-bright', mix(primary, 82, 'white'))
      set('--brand-deep', mix(primary, 82, 'black'))

      // The identity's dark surfaces follow the workspace's own secondary colour, so it carries none of ours.
      set('--chrome-1', secondary)
      set('--chrome-2', mix(secondary, 86, 'white'))
      set('--chrome-active', primary)
      set('--chrome-active-ink', readableOn(primary))
      set('--chrome-muted', mix(primary, 38, 'white'))

      // The accent is the workspace's colour too: no copper in a white-label product.
      set('--accent', primary)
      set('--accent-text', mix(primary, 70, 'black'))
      set('--accent-soft', mix(primary, 12, 'var(--color-surface)'))
      set('--chart-seq-old', mix(primary, 14, 'white'))
      set('--chart-seq-mid', mix(primary, 42, 'white'))
      set('--chart-seq-latest', primary)
    } else {
      // No colours of their own: the stylesheet's identity applies, exactly as written there.
      for (const name of TENANT_PROPERTIES) root.style.removeProperty(name)
    }

    // The browser's own chrome on a phone, so a tenant's app reaches the
    // status bar rather than stopping at the viewport.
    document
      .querySelector<HTMLMetaElement>("meta[name='theme-color']")
      ?.setAttribute('content', own ? primary : PLATFORM_THEME_COLOR)

    document.title = branding.system_name || PLATFORM_TITLE

    /*
     * Written on every branding change, not only when the tenant has one of
     * their own: signing out of a branded tenant and into an unbranded one
     * would otherwise leave the previous customer's icon and title in the tab,
     * which is the one white-label leak a user can see without looking.
     */
    setIcons(branding.favicon_url || null)
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

  /*
   * Absolute addresses throughout: this manifest lives at a blob: URL, and a relative address in
   * it would be resolved against that and point nowhere. The tenant's own icon leads when they
   * have one; the platform's PNGs follow, because an installable app needs at least one PNG of
   * 192 and 512 whatever the tenant uploaded.
   */
  const origin = window.location.origin
  const abs = (path: string) => (/^https?:\/\//.test(path) ? path : `${origin}${path.startsWith('/') ? '' : '/'}${path}`)
  const own = branding.favicon_url ? abs(branding.favicon_url) : null

  const manifest = {
    id: `${origin}/`,
    name,
    short_name: name,
    lang: branding.locale || 'ar',
    dir: (branding.locale || 'ar') === 'ar' ? 'rtl' : 'ltr',
    start_url: `${origin}/?source=pwa`,
    scope: `${origin}/`,
    display: 'standalone',
    display_override: ['standalone', 'minimal-ui'],
    orientation: 'any',
    background_color: PLATFORM_BACKGROUND,
    theme_color: hasOwnColours(branding) ? branding.primary_color : PLATFORM_THEME_COLOR,
    icons: [
      ...(own ? [{ src: own, sizes: 'any', type: own.split('?')[0].endsWith('.svg') ? 'image/svg+xml' : 'image/png', purpose: 'any' }] : []),
      { src: abs('/android-chrome-192x192.png'), sizes: '192x192', type: 'image/png', purpose: 'any' },
      { src: abs('/android-chrome-512x512.png'), sizes: '512x512', type: 'image/png', purpose: 'any' },
      { src: abs('/maskable-icon-512x512.png'), sizes: '512x512', type: 'image/png', purpose: 'maskable' },
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
