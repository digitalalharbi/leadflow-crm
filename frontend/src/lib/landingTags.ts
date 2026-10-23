import type { LandingTag } from '@/features/hub'

/**
 * The tags a landing page may load — and only these, from only these hosts. A tag id arrives from the server, was checked
 * for its shape when it was saved, and is checked again here: a page that loads whatever it is told is a page that can be
 * made to run anything.
 */
const ID = /^[A-Za-z0-9_-]{3,40}$/

const SOURCES: Record<string, (id: string) => string> = {
  ga4: (id) => `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(id)}`,
  google_ads: (id) => `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(id)}`,
  gtm_web: (id) => `https://www.googletagmanager.com/gtm.js?id=${encodeURIComponent(id)}`,
  meta: () => 'https://connect.facebook.net/en_US/fbevents.js',
  tiktok: (id) => `https://analytics.tiktok.com/i18n/pixel/events.js?sdkid=${encodeURIComponent(id)}`,
  snapchat: () => 'https://sc-static.net/scevent.min.js',
  linkedin: () => 'https://snap.licdn.com/li.lt/insight.min.js',
  x: () => 'https://static.ads-twitter.com/uwt.js',
}

type Win = Window & Record<string, unknown> & { dataLayer?: unknown[] }

export const isLoadable = (tag: LandingTag): boolean => tag.kind in SOURCES && ID.test(tag.id)

export const tagSource = (tag: LandingTag): string | null => (isLoadable(tag) ? SOURCES[tag.kind](tag.id) : null)

/** The page's dataLayer: always there, always a plain array, so a GTM container that loads later reads what came before. */
export function pushDataLayer(entry: Record<string, unknown>): void {
  const w = window as unknown as Win
  w.dataLayer = w.dataLayer ?? []
  w.dataLayer.push(entry)
}

function gtag(...args: unknown[]): void {
  const w = window as unknown as Win
  w.dataLayer = w.dataLayer ?? []
  // gtag reads `arguments`, not an array: the object it queues must be an Arguments object.
  w.dataLayer.push(function () { return arguments }.apply(null, args as []))
}

function inject(src: string): void {
  if (document.querySelector(`script[data-mt-tag="${CSS.escape(src)}"]`)) return
  const el = document.createElement('script')
  el.async = true
  el.src = src
  el.dataset.mtTag = src
  document.head.appendChild(el)
}

/** Loads each tag once the visitor has agreed, and starts it. Safe to call again: nothing loads twice. */
export function loadTags(tags: LandingTag[]): void {
  const w = window as unknown as Win

  for (const tag of tags.filter(isLoadable)) {
    const src = tagSource(tag)!

    if (tag.kind === 'ga4' || tag.kind === 'google_ads') {
      gtag('js', new Date())
      gtag('config', tag.id)
    } else if (tag.kind === 'gtm_web') {
      pushDataLayer({ 'gtm.start': Date.now(), event: 'gtm.js' })
    } else if (tag.kind === 'meta' && typeof w.fbq !== 'function') {
      const queue: unknown[][] = []
      const fbq = (...args: unknown[]) => { queue.push(args) }
      ;(fbq as unknown as { queue: unknown[][] }).queue = queue
      w.fbq = fbq
      ;(w.fbq as (...a: unknown[]) => void)('init', tag.id)
      ;(w.fbq as (...a: unknown[]) => void)('track', 'PageView')
    } else if (tag.kind === 'tiktok' && !w.ttq) {
      const calls: unknown[][] = []
      w.ttq = { push: (...a: unknown[]) => calls.push(a), track: (...a: unknown[]) => calls.push(['track', ...a]), page: () => calls.push(['page']) }
      ;(w.ttq as { page: () => void }).page()
    }

    inject(src)
  }
}

/**
 * The form was submitted: tell the page's own tags, with the id the server recorded the same submission under, so a platform
 * that also hears it from the server counts it once.
 */
export function trackSubmit(tags: LandingTag[], eventId: string): void {
  const w = window as unknown as Win
  pushDataLayer({ event: 'form_submitted', event_id: eventId })

  for (const tag of tags.filter(isLoadable)) {
    if (tag.kind === 'meta' && typeof w.fbq === 'function') (w.fbq as (...a: unknown[]) => void)('track', 'Lead', {}, { eventID: eventId })
    if (tag.kind === 'tiktok' && w.ttq) (w.ttq as { track: (...a: unknown[]) => void }).track('SubmitForm', {}, { event_id: eventId })
    if (tag.kind === 'ga4') gtag('event', 'generate_lead', { event_id: eventId })
  }
}
