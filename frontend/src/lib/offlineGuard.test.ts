import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiError, http } from '@/lib/api'

/**
 * With no connection a change is refused before it is sent: not queued, not retried later, and said
 * out loud. Reading is left alone (the network fails it as it always did).
 */
describe('the offline guard', () => {
  afterEach(() => vi.unstubAllGlobals())

  const offline = () => vi.stubGlobal('navigator', { ...globalThis.navigator, onLine: false })

  it('refuses a write while offline with the offline error, without touching the network', async () => {
    offline()
    const adapter = vi.fn()

    await expect(http.post('/leads', {}, { adapter })).rejects.toMatchObject({ message: 'offline', status: 0 })
    await expect(http.patch('/auth/notification-preferences', {}, { adapter })).rejects.toBeInstanceOf(ApiError)
    expect(adapter).not.toHaveBeenCalled()
  })

  it('lets a read through', async () => {
    offline()
    const adapter = vi.fn().mockResolvedValue({ data: { data: 1 }, status: 200, statusText: 'OK', headers: {}, config: {} })

    await http.get('/leads', { adapter })

    expect(adapter).toHaveBeenCalledOnce()
  })
})
