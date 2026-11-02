/**
 * A failed request, already translated into something safe to show a user.
 * Raw server messages and stack traces never reach the UI.
 */
export class ApiError extends Error {
  status: number
  errors: Record<string, string[]> | null

  constructor(message: string, status: number, errors: Record<string, string[]> | null = null) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors
  }

  /** First message for a given form field, for inline validation display. */
  fieldError(field: string): string | undefined {
    return this.errors?.[field]?.[0]
  }
}

type ErrorKey =
  | 'offline'
  | 'network'
  | 'timeout'
  | 'unauthenticated'
  | 'forbidden'
  | 'notFound'
  | 'validation'
  | 'rateLimited'
  | 'subscription'
  | 'server'
  | 'unknown'

/** Status -> i18n key. The UI translates; the client never invents prose. */
export function errorKeyForStatus(status: number): ErrorKey {
  switch (status) {
    case 0:
      return 'network'
    case 401:
      return 'unauthenticated'
    case 402:
      return 'subscription'
    case 403:
      return 'forbidden'
    case 404:
      return 'notFound'
    case 413:
    case 422:
      return 'validation'
    case 429:
      return 'rateLimited'
    default:
      return status >= 500 ? 'server' : 'unknown'
  }
}
