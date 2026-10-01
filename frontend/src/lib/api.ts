import axios, { AxiosError, type AxiosInstance } from 'axios'
import type { ApiEnvelope } from '@/types/api'

/** Where the token lives. Kept in one place so logout can be exhaustive. */
const TOKEN_KEY = 'leadflow.token'

export const tokenStorage = {
  get: () => {
    try {
      return localStorage.getItem(TOKEN_KEY)
    } catch {
      return null
    }
  },
  set: (token: string) => {
    try {
      localStorage.setItem(TOKEN_KEY, token)
    } catch {
      /* private browsing — the session simply won't survive a reload */
    }
  },
  clear: () => {
    try {
      localStorage.removeItem(TOKEN_KEY)
    } catch {
      /* ignore */
    }
  },
}

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

export const http: AxiosInstance = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? '/api/v1',
  headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
  timeout: 30_000,
})

http.interceptors.request.use((config) => {
  const token = tokenStorage.get()

  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }

  return config
})

/** Called when the server says the session is gone, so the app can reset. */
let onUnauthenticated: (() => void) | null = null

export function setUnauthenticatedHandler(handler: () => void) {
  onUnauthenticated = handler
}

type ErrorKey =
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

http.interceptors.response.use(
  (response) => response,
  (error: AxiosError<ApiEnvelope<unknown>>) => {
    if (error.code === 'ECONNABORTED') {
      return Promise.reject(new ApiError('timeout', 0))
    }

    if (!error.response) {
      return Promise.reject(new ApiError('network', 0))
    }

    const { status, data } = error.response

    if (status === 401) {
      tokenStorage.clear()
      onUnauthenticated?.()
    }

    // 422 carries field errors the forms render; everything else gets a generic
    // translated message so backend internals never surface.
    const message = status === 422 ? (data?.message ?? 'validation') : errorKeyForStatus(status)

    return Promise.reject(new ApiError(message, status, data?.errors ?? null))
  },
)

/** Unwraps the { success, data } envelope so callers work with plain payloads. */
export async function apiGet<T>(url: string, params?: Record<string, unknown>): Promise<T> {
  const { data } = await http.get<ApiEnvelope<T>>(url, { params })
  return data.data
}

export async function apiGetPaginated<T>(url: string, params?: Record<string, unknown>) {
  const { data } = await http.get<ApiEnvelope<T>>(url, { params })
  return { data: data.data, meta: data.meta }
}

export async function apiPost<T>(url: string, body?: unknown): Promise<T> {
  const { data } = await http.post<ApiEnvelope<T>>(url, body)
  return data.data
}

export async function apiPatch<T>(url: string, body?: unknown): Promise<T> {
  const { data } = await http.patch<ApiEnvelope<T>>(url, body)
  return data.data
}

export async function apiDelete<T>(url: string): Promise<T> {
  const { data } = await http.delete<ApiEnvelope<T>>(url)
  return data.data
}

/** Triggers a browser download for the CSV export endpoints. */
export async function apiDownload(url: string, params: Record<string, unknown>, filename: string) {
  const response = await http.get(url, { params, responseType: 'blob' })
  const href = URL.createObjectURL(response.data as Blob)
  const link = document.createElement('a')

  link.href = href
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(href)
}
