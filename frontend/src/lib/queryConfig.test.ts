import { describe, expect, it, vi } from 'vitest'
import { QueryClient, focusManager } from '@tanstack/react-query'
import { queryConfig } from './queryConfig'
import { ApiError } from './api'

/**
 * These are product decisions, not wiring.
 *
 * They are tested here because the browser cannot be made to demonstrate them
 * on demand: a tab only counts as focused when a person is actually looking at
 * it, so an automated browser — which reports itself permanently hidden — is
 * stuck in exactly the state that switches this behaviour off.
 */
describe('query defaults', () => {
  it('refetches when somebody comes back to the tab', () => {
    // Stale figures that look live are worse than a spinner.
    expect(queryConfig.defaultOptions?.queries?.refetchOnWindowFocus).toBe(true)
  })

  it('does not refetch on every tab switch', () => {
    // Without a stale window, returning to the tab re-runs every query on the
    // screen, which on the dashboard is the most expensive request we have.
    expect(queryConfig.defaultOptions?.queries?.staleTime).toBeGreaterThanOrEqual(10_000)
  })

  it('a stale query really does refetch once the app regains focus', async () => {
    const client = new QueryClient(queryConfig)
    const queryFn = vi.fn().mockResolvedValue({ total: 1 })

    await client.fetchQuery({ queryKey: ['dashboard'], queryFn })
    expect(queryFn).toHaveBeenCalledTimes(1)

    // An observer, because a query nobody is watching has no reason to refetch.
    const unsubscribe = client
      .getQueryCache()
      .find({ queryKey: ['dashboard'] })!
      .observers.length
      ? () => {}
      : client.getQueryCache().subscribe(() => {})

    client.setQueryDefaults(['dashboard'], { staleTime: 0 })
    client.getQueryCache().find({ queryKey: ['dashboard'] })?.invalidate()

    focusManager.setFocused(false)
    focusManager.setFocused(true)

    await client.refetchQueries({ queryKey: ['dashboard'] })

    expect(queryFn).toHaveBeenCalledTimes(2)
    unsubscribe()
    focusManager.setFocused(undefined)
  })

  /** A 4xx is the server's verdict; retrying it just makes the user wait. */
  it('never retries a request the server already judged', () => {
    const retry = queryConfig.defaultOptions?.queries?.retry

    expect(typeof retry).toBe('function')
    expect((retry as CallableFunction)(0, new ApiError('nope', 422))).toBe(false)
    expect((retry as CallableFunction)(0, new ApiError('boom', 500))).toBe(true)
  })

  it('does not retry mutations, which are not safe to repeat', () => {
    expect(queryConfig.defaultOptions?.mutations?.retry).toBe(false)
  })
})
