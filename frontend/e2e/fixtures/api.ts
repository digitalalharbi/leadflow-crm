import { request, type APIRequestContext } from '@playwright/test'
import { PASSWORD } from './data'

const API_URL = process.env.E2E_API_URL ?? 'http://127.0.0.1:8000'

export interface ApiSession {
  context: APIRequestContext
  token: string
  dispose: () => Promise<void>
}

/**
 * Signs in against the API directly.
 *
 * Used for two things the browser should not be doing: reading another tenant's
 * ids so isolation can be probed, and asserting that the backend really changed
 * after a UI action rather than trusting the optimistic render.
 */
export async function apiLogin(email: string, password: string = PASSWORD): Promise<ApiSession> {
  const context = await request.newContext({ baseURL: API_URL })

  const response = await context.post('/api/v1/auth/login', {
    data: { email, password },
    headers: { Accept: 'application/json' },
  })

  if (!response.ok()) {
    throw new Error(`API login failed for ${email}: ${response.status()} ${await response.text()}`)
  }

  const body = await response.json()
  const token = body.data.token as string

  const authed = await request.newContext({
    baseURL: API_URL,
    extraHTTPHeaders: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
  })

  return {
    context: authed,
    token,
    dispose: async () => {
      await authed.dispose()
      await context.dispose()
    },
  }
}

/** Unwraps the { success, data } envelope and fails loudly on a non-2xx. */
export async function apiGet<T>(session: ApiSession, path: string): Promise<T> {
  const response = await session.context.get(path)

  if (!response.ok()) {
    throw new Error(`GET ${path} -> ${response.status()}: ${await response.text()}`)
  }

  const body = await response.json()
  return body.data as T
}

export async function apiGetRaw(session: ApiSession, path: string) {
  return session.context.get(path)
}

export async function apiPost(session: ApiSession, path: string, data: unknown = {}) {
  return session.context.post(path, { data })
}

/** Posts to the public ingestion endpoint — no session, by design. */
export async function postWebhook(
  key: string,
  secret: string | null,
  payload: Record<string, unknown>,
) {
  const context = await request.newContext({ baseURL: API_URL })

  try {
    return await context.post(`/api/v1/integrations/webhooks/${key}/leads`, {
      data: payload,
      headers: {
        Accept: 'application/json',
        ...(secret ? { 'X-Webhook-Secret': secret } : {}),
      },
      failOnStatusCode: false,
    })
  } finally {
    await context.dispose()
  }
}

export { API_URL }
