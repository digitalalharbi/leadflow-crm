/*
 * مهتم — the service worker.
 *
 * It exists to make the product installable and quick to open, and to say plainly when there is no
 * connection. It does NOT make the product work offline: nothing from the API is ever cached, so a
 * screen can never show a stale customer list as if it were live, and no change can be queued up to
 * run later without somebody present. Page navigations go to the network; when there is none they
 * get the offline page, not a stale copy of the app.
 *
 * Built by the Vite plugin in vite.config.ts, which replaces __BUILD_ID__ with a hash of the bundle:
 * a new build is a new file, so the browser sees an update and the app can offer it.
 */
const BUILD = '__BUILD_ID__'
const SHELL_CACHE = `mohtam-shell-${BUILD}`
const ASSET_CACHE = `mohtam-assets-${BUILD}`
const OLD_CACHE = /^(mohtam|mahtam)-/

// What the offline page and the install prompt need, and nothing that carries data.
/** Icons, launch screens, fonts for the offline page and the manifest: small, rarely changed, safe to serve from cache. */
const STATIC_ASSET = /^\/(brand\/|splash\/|fonts\/|favicon|android-chrome|maskable-icon|apple-touch-icon|site\.webmanifest)/

const PRECACHE = ['/offline.html', '/site.webmanifest?v=2', '/brand/favicon.svg?v=2', '/brand/pwa-192.png?v=2', '/brand/pwa-512.png?v=2', '/fonts/ibm-plex-sans-arabic-arabic-400-normal.woff2', '/fonts/ibm-plex-sans-arabic-arabic-600-normal.woff2']

self.addEventListener('install', (event) => {
  // No skipWaiting here: a waiting worker is what lets the app tell the person "an update is ready"
  // and swap at a moment they choose, rather than changing code under a form they are filling in.
  // One file missing or slow must not stop the worker installing: each is fetched on its own, and a miss is
  // simply not cached (it is fetched normally later). `cache: 'reload'` skips the HTTP cache for a fresh copy.
  event.waitUntil(
    caches.open(SHELL_CACHE).then((cache) =>
      Promise.allSettled(PRECACHE.map((path) => cache.add(new Request(path, { cache: 'reload' })))),
    ),
  )
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      const keep = new Set([SHELL_CACHE, ASSET_CACHE])

      for (const key of await caches.keys()) {
        // Every earlier build's caches, under today's name and the old spelling, so an installed app never piles them up.
        if (OLD_CACHE.test(key) && !keep.has(key)) await caches.delete(key)
      }

      await self.clients.claim()
    })(),
  )
})

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') self.skipWaiting()
})

self.addEventListener('fetch', (event) => {
  const request = event.request
  const url = new URL(request.url)

  // Writes, other origins and the API are never touched: the network answers or it does not.
  if (request.method !== 'GET' || url.origin !== self.location.origin || url.pathname.startsWith('/api/')) return

  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).catch(async () => (await caches.match('/offline.html')) || Response.error()))

    return
  }

  // Hashed build files never change under the same name: the first answer is the answer.
  if (url.pathname.startsWith('/assets/')) {
    event.respondWith(
      caches.open(ASSET_CACHE).then(async (cache) => {
        const hit = await cache.match(request)

        if (hit) return hit

        const response = await fetch(request)

        if (response.ok && response.type === 'basic') cache.put(request, response.clone())

        return response
      }),
    )

    return
  }

  // Icons, splash screens and the manifest: fast from cache, refreshed behind the scenes.
  if (STATIC_ASSET.test(url.pathname)) {
    event.respondWith(
      caches.open(SHELL_CACHE).then(async (cache) => {
        const hit = await cache.match(request)
        const refresh = fetch(request)
          .then((response) => {
            if (response.ok) cache.put(request, response.clone())

            return response
          })
          .catch(() => hit)

        return hit || refresh
      }),
    )
  }
})
