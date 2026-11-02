import { ApiError, errorKeyForStatus } from '@/lib/apiError'
import type { LandingSubmitInput, LandingSubmitResult, PublicLanding } from '@/features/hub'

/*
 * The two calls a public landing page makes. On plain fetch, away from the product's API client: a stranger opening a page from an ad has no
 * session, company or token to attach, and should not download what handles them.
 */
const BASE = import.meta.env.VITE_API_URL ?? '/api/v1'

async function send<T>(path: string, body?: unknown): Promise<T> {
  // With no connection a submission is refused here, not queued: the form says so and keeps the answers.
  if (body !== undefined && navigator.onLine === false) throw new ApiError('offline', 0)

  let response: Response

  try {
    response = await fetch(`${BASE}${path}`, {
      method: body === undefined ? 'GET' : 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
      signal: AbortSignal.timeout?.(30_000),
    })
  } catch {
    throw new ApiError('network', 0)
  }

  const payload = (await response.json().catch(() => null)) as { data: T; message?: string; errors?: Record<string, string[]> | null } | null

  if (!response.ok) {
    throw new ApiError(response.status === 422 ? (payload?.message ?? 'validation') : errorKeyForStatus(response.status), response.status, payload?.errors ?? null)
  }

  return (payload as { data: T }).data
}

export function fetchLanding(token: string, query: Record<string, string>): Promise<PublicLanding> {
  const search = new URLSearchParams(query).toString()

  return send<PublicLanding>(`/public/landing/${token}${search ? `?${search}` : ''}`)
}

export function submitLanding(token: string, input: LandingSubmitInput): Promise<LandingSubmitResult> {
  return send<LandingSubmitResult>(`/public/landing/${token}/submit`, input)
}
