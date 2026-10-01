import { describe, expect, it } from 'vitest'
import { renderHook } from '@testing-library/react'
import { useApiError } from './useApiError'
import { ApiError } from '@/lib/api'
import i18n from '@/i18n'

describe('useApiError', () => {
  it('translates a status into a message a user can act on', async () => {
    await i18n.changeLanguage('en')
    const { result } = renderHook(() => useApiError())

    expect(result.current(new ApiError('forbidden', 403))).toBe(
      'You do not have permission to do that.',
    )
    expect(result.current(new ApiError('rateLimited', 429))).toContain('Too many attempts')
    expect(result.current(new ApiError('network', 0))).toContain('Could not reach the server')
  })

  it('surfaces the first field error for a 422', () => {
    const { result } = renderHook(() => useApiError())

    const error = new ApiError('validation', 422, {
      phone: ['Another lead already uses this phone number.'],
    })

    expect(result.current(error)).toBe('Another lead already uses this phone number.')
  })

  it('never leaks a raw server message for a 500', () => {
    const { result } = renderHook(() => useApiError())

    const message = result.current(
      new ApiError('SQLSTATE[42703]: column leads.secret does not exist', 500),
    )

    expect(message).not.toContain('SQLSTATE')
    expect(message).toContain('Something went wrong')
  })

  it('falls back for a non-ApiError throw', () => {
    const { result } = renderHook(() => useApiError())

    expect(result.current(new TypeError('x is not a function'))).toContain('Something went wrong')
  })

  it('speaks Arabic when the app is in Arabic', async () => {
    await i18n.changeLanguage('ar')
    const { result } = renderHook(() => useApiError())

    expect(result.current(new ApiError('forbidden', 403))).toBe('ليس لديك صلاحية للقيام بهذا الإجراء.')
  })
})
