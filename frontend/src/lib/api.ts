import axios, { AxiosError, type AxiosInstance } from 'axios'
import type { ApiEnvelope } from '@/types/api'
import { useCompany } from '@/stores/company'
import { ApiError, errorKeyForStatus } from '@/lib/apiError'

export { ApiError, errorKeyForStatus }

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

export const http: AxiosInstance = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? '/api/v1',
  headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
  timeout: 30_000,
})

/**
 * With no connection, a change is refused here, before it is sent — not queued, not retried later.
 * Reading falls through to the network and fails as it always did; writing (and signing in) never
 * pretends to have happened. The app shows the offline notice; this is what makes it true.
 */
http.interceptors.request.use((config) => {
  if (typeof navigator !== 'undefined' && navigator.onLine === false && (config.method ?? 'get').toLowerCase() !== 'get') {
    return Promise.reject(new ApiError('offline', 0))
  }

  const token = tokenStorage.get()

  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }

  /*
   * The company being looked at, on every request but the one that lists
   * companies. That list is how a stale choice gets noticed — a company that
   * was deactivated, or that this person was taken out of — and a list that
   * sent the stale choice would be refused for it, leaving no way to see
   * that it is stale.
   */
  const company = useCompany.getState().selected
  const isCompanyList = config.method === 'get' && config.url === '/companies'

  if (company && !isCompanyList) {
    config.headers['X-Company-Id'] = company
  }

  return config
})

/** Called when the server says the session is gone, so the app can reset. */
let onUnauthenticated: (() => void) | null = null

export function setUnauthenticatedHandler(handler: () => void) {
  onUnauthenticated = handler
}

/** Called when the workspace requires a second factor and this person has none yet. */
let onMfaRequired: (() => void) | null = null

export function setMfaRequiredHandler(handler: () => void) {
  onMfaRequired = handler
}

/** Called when the server says the workspace's subscription is closed (402), with the state it named. */
let onSubscriptionClosed: ((state: string) => void) | null = null

export function setSubscriptionClosedHandler(handler: (state: string) => void) {
  onSubscriptionClosed = handler
}

http.interceptors.response.use(
  (response) => response,
  (error: AxiosError<ApiEnvelope<unknown>> | ApiError) => {
    // Already translated (the offline guard above): pass it on as it is.
    if (error instanceof ApiError) {
      return Promise.reject(error)
    }

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

    // The workspace requires a second factor this person has not set up: send them to set it up.
    if (status === 403 && (data?.data as { code?: string } | null | undefined)?.code === 'mfa_enrolment_required') {
      onMfaRequired?.()
    }

    if (status === 402) {
      const state = (data?.errors as Record<string, string[]> | null | undefined)?.state?.[0]
      onSubscriptionClosed?.(state ?? 'expired')
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

export async function apiPut<T>(url: string, body?: unknown): Promise<T> {
  const { data } = await http.put<ApiEnvelope<T>>(url, body)
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
