import { request, type APIRequestContext } from '@playwright/test'
import { createHmac } from 'node:crypto'

import { PASSWORD, WHATSAPP } from './data'

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
export async function apiLogin(email: string, password: string = PASSWORD, door: '/api/v1/auth/login' | '/api/v1/platform/auth/login' = '/api/v1/auth/login'): Promise<ApiSession> {
  const context = await request.newContext({ baseURL: API_URL })

  const response = await context.post(door, {
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

/**
 * Delivers a WhatsApp Cloud API payload the way Meta does — signed with the
 * tenant's app secret, over the raw body, because the server verifies the bytes
 * it received rather than a re-serialisation of them.
 */
export async function postWhatsappWebhook(
  payload: unknown,
  appSecret: string | null = WHATSAPP.appSecret,
): Promise<{ status: number; body: { data?: Record<string, number> } }> {
  const context = await request.newContext({ baseURL: API_URL })
  const raw = JSON.stringify(payload)

  try {
    const response = await context.post('/api/v1/integrations/whatsapp/webhook', {
      data: raw,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...(appSecret
          ? { 'X-Hub-Signature-256': `sha256=${createHmac('sha256', appSecret).update(raw).digest('hex')}` }
          : {}),
      },
      failOnStatusCode: false,
    })

    // Read the body before the context is disposed: a disposed response cannot
    // be parsed, and the counts are the whole point of asserting on it.
    const status = response.status()
    const body = status === 200 ? await response.json() : {}

    return { status, body }
  } finally {
    await context.dispose()
  }
}

/** An inbound text message, in the shape the Cloud API sends. */
export function inboundWhatsappMessage(from: string, text: string, id = `wamid.e2e.${Date.now()}`) {
  return {
    object: 'whatsapp_business_account',
    entry: [{
      id: '900900900',
      changes: [{
        field: 'messages',
        value: {
          messaging_product: 'whatsapp',
          metadata: { display_phone_number: '966500000000', phone_number_id: WHATSAPP.phoneNumberId },
          contacts: [{ profile: { name: 'عميل' }, wa_id: from }],
          messages: [{ from, id, timestamp: String(Math.floor(Date.now() / 1000)), type: 'text', text: { body: text } }],
        },
      }],
    }],
  }
}

export { API_URL }

/** The platform owner's session — from the platform's own door, never a company's login. */
export function apiPlatformLogin(email: string, password: string = PASSWORD): Promise<ApiSession> {
  return apiLogin(email, password, '/api/v1/platform/auth/login')
}
