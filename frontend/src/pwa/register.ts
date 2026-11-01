import { usePwa } from '@/stores/pwa'

/**
 * Wires the app to whether it is online and whether it was opened from a home-screen icon, and registers the
 * service worker — in a production build only: in development a worker caching the dev server's
 * files is a way to spend an afternoon wondering why an edit does not show.
 */
export function startPwa(): void {
  if (typeof window === 'undefined') return

  // Which build this is — visible in a console and, being part of the bundle, part of what makes a build a new version.
  ;(window as unknown as { __MAHTAM_BUILD__?: string }).__MAHTAM_BUILD__ = import.meta.env.VITE_BUILD_LABEL ?? 'dev'

  const pwa = usePwa.getState()

  pwa.setOnline(navigator.onLine)
  window.addEventListener('online', () => usePwa.getState().setOnline(true))
  window.addEventListener('offline', () => usePwa.getState().setOnline(false))

  const standalone = window.matchMedia?.('(display-mode: standalone)')
  const isStandalone = () => Boolean(standalone?.matches) || (navigator as Navigator & { standalone?: boolean }).standalone === true

  // Opened from a home-screen icon: the stylesheet can drop what only makes sense inside a browser.
  const mark = () => {
    pwa.setStandalone(isStandalone())
    document.documentElement.toggleAttribute('data-standalone', isStandalone())
  }

  mark()
  standalone?.addEventListener?.('change', mark)

  // The browser's own "install" offer is left alone: this product never nags anyone to install it.

  if (import.meta.env.PROD && 'serviceWorker' in navigator) {
    void registerWorker()
  }
}

async function registerWorker(): Promise<void> {
  // A first-ever install claims the page without there having been a worker before: that is not an update, and must not reload.
  const hadController = Boolean(navigator.serviceWorker.controller)
  let reloading = false

  navigator.serviceWorker.addEventListener('controllerchange', () => {
    if (hadController && !reloading) {
      reloading = true
      window.location.reload()
    }
  })

  try {
    const registration = await navigator.serviceWorker.register('/sw.js', { scope: '/', updateViaCache: 'none' })

    const offer = (worker: ServiceWorker | null) => {
      if (worker && navigator.serviceWorker.controller) usePwa.getState().setWaiting(worker)
    }

    offer(registration.waiting)
    registration.addEventListener('updatefound', () => {
      const worker = registration.installing

      worker?.addEventListener('statechange', () => {
        if (worker.state === 'installed') offer(worker)
      })
    })

    // A tab left open for days should still learn of a new version.
    const check = () => void registration.update().catch(() => undefined)

    window.setInterval(check, 60 * 60 * 1000)
    document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && check())
  } catch {
    // No worker: the product works exactly as it did, just without installing or the offline page.
  }
}
