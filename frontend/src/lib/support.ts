import { apiPost, tokenStorage } from '@/lib/api'

/*
 * A support session swaps the console's token for a short-lived one inside the company, and keeps the console's aside
 * (this tab only) to return to. Ending it calls the server — which revokes the session and writes both trails — and
 * puts the console's token back.
 */
const PLATFORM_KEY = 'mohtam.platform-token'

export function stashPlatformToken() {
  const current = tokenStorage.get()

  try {
    if (current) sessionStorage.setItem(PLATFORM_KEY, current)
  } catch {
    /* without session storage the owner simply signs in again afterwards */
  }
}

export function hasStashedPlatformToken(): boolean {
  try {
    return Boolean(sessionStorage.getItem(PLATFORM_KEY))
  } catch {
    return false
  }
}

export async function endSupportSession(): Promise<boolean> {
  try {
    await apiPost('/auth/impersonation/stop')
  } catch {
    // The session may already have expired; returning to the console is what matters.
  }

  let platform: string | null = null

  try {
    platform = sessionStorage.getItem(PLATFORM_KEY)
    sessionStorage.removeItem(PLATFORM_KEY)
  } catch {
    platform = null
  }

  if (platform) {
    tokenStorage.set(platform)
    return true
  }

  tokenStorage.clear()
  return false
}
