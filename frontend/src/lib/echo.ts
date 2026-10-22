import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { tokenStorage } from '@/lib/api'

/**
 * The live connection, or nothing at all.
 *
 * A websocket server is a process somebody has to run. There is none in
 * development, none in CI, and none in a deployment that has not configured it
 * — so this returns null in all three and the app keeps refetching on its timer
 * exactly as it did before. A product that breaks when its nicest feature is
 * switched off is worse than one without the feature.
 *
 * Deliberately not a fallback that reconnects forever in the background: a
 * browser retrying a socket that will never exist is a battery and a log full
 * of noise, and it hides the real failure from whoever is deploying.
 */

type Window = typeof globalThis & { Pusher?: typeof Pusher }

const key = import.meta.env.VITE_REVERB_APP_KEY
const host = import.meta.env.VITE_REVERB_HOST
const port = import.meta.env.VITE_REVERB_PORT
const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'https'

export function isLiveFeedConfigured(): boolean {
  return Boolean(key && host)
}

let echo: Echo<'reverb'> | null = null

export function getEcho(): Echo<'reverb'> | null {
  if (!isLiveFeedConfigured()) {
    return null
  }

  if (echo) {
    return echo
  }

  // laravel-echo reads Pusher off the global rather than taking it as an
  // argument, which is the one piece of setup it will not do for you.
  ;(globalThis as Window).Pusher = Pusher

  echo = new Echo({
    broadcaster: 'reverb',
    key,
    wsHost: host,
    wsPort: Number(port ?? 80),
    wssPort: Number(port ?? 443),
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    /*
     * The same Bearer token the rest of the client uses, sent to the API's own
     * authorisation endpoint. The channels are private, so every subscription
     * is checked against the workspace the person belongs to — see
     * routes/channels.php.
     */
    authEndpoint: (import.meta.env.VITE_API_URL ?? '/api/v1').replace(/\/v1\/?$/, '') + '/broadcasting/auth',
    auth: {
      headers: {
        get Authorization() {
          return `Bearer ${tokenStorage.get() ?? ''}`
        },
        Accept: 'application/json',
      },
    },
  })

  return echo
}

/** Lets a sign-out drop the socket with the session it belonged to. */
export function disconnectEcho(): void {
  echo?.disconnect()
  echo = null
}
