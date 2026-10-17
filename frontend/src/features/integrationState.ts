import { useQuery } from '@tanstack/react-query'
import { apiGet } from '@/lib/api'

/**
 * How far an integration has got, in words that do not overclaim.
 *
 * "Connected" is deliberately not here. It meant "a row exists", "a token was
 * typed" and "the provider said yes" at once, and it appeared beside the demo
 * provider on the screen where somebody was deciding whether customers were
 * being messaged. These six are computed on the server from evidence, in this
 * order, weakest first.
 */
export const INTEGRATION_STATES = [
  'not_configured',
  'code_verified',
  'sandbox_verified',
  'ready',
  'live_verified',
  'production_verified',
] as const

export type IntegrationState = (typeof INTEGRATION_STATES)[number]

/** Tone follows the ladder: grey until something real has happened, then green. */
export const STATE_TONE: Record<IntegrationState, 'neutral' | 'warning' | 'success'> = {
  not_configured: 'neutral',
  code_verified: 'neutral',
  sandbox_verified: 'warning',
  ready: 'warning',
  live_verified: 'success',
  production_verified: 'success',
}

/** Whether a real counterpart has been observed — the line between "set up" and "working". */
export function isProven(state: IntegrationState | undefined): boolean {
  return state === 'live_verified' || state === 'production_verified'
}

/** One shared read, so the grid and the cards can never disagree about a state. */
export function useIntegrationStates() {
  const { data } = useQuery({
    queryKey: ['integration-health'],
    queryFn: () =>
      apiGet<{ integrations: Array<{ key: string; state: IntegrationState }> }>('/integrations/health'),
  })

  const states = new Map<string, IntegrationState>(
    (data?.integrations ?? []).map((row) => [row.key, row.state]),
  )

  return states
}
