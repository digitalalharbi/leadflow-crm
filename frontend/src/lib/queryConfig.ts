import type { QueryClientConfig } from '@tanstack/react-query'
import { ApiError } from '@/lib/api'

/**
 * How every query in the app behaves by default.
 *
 * Extracted from `main.tsx` so these decisions can be asserted. They are
 * product decisions, not wiring: whether a manager's dashboard goes stale
 * while they are looking at it is not something to discover later from a
 * support ticket.
 */
export const queryConfig: QueryClientConfig = {
  defaultOptions: {
    queries: {
      staleTime: 30_000,

      /*
       * A manager who leaves the dashboard open on a second monitor and comes
       * back to it was, until now, reading whatever the numbers were when they
       * opened it — with nothing on screen saying so. Figures that are stale
       * but look live are worse than a spinner.
       *
       * `staleTime` still applies, so flicking between tabs does not refetch
       * everything every time. And the browser only counts a tab as focused
       * when somebody is actually looking at it, so a tab left open in the
       * background costs nothing.
       */
      refetchOnWindowFocus: true,

      retry: (failureCount, error) => {
        // Never retry a request the server has already judged; only transient faults.
        if (error instanceof ApiError && error.status >= 400 && error.status < 500) return false

        return failureCount < 2
      },
    },
    /*
     * `networkMode: 'always'` for changes. TanStack's default ('online') quietly PAUSES a mutation
     * while the browser is offline and fires it when the connection returns — a save pressed on a
     * train would complete minutes later, unseen, possibly over someone else's newer edit. We
     * refuse instead: the request guard in `lib/api.ts` rejects a non-GET while offline, so the
     * person is told at once that nothing was saved.
     */
    mutations: { retry: false, networkMode: 'always' },
  },
}
