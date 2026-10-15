import { afterEach, describe, expect, it, vi } from 'vitest'
import { pickSpreadsheet } from './googlePicker'

/**
 * Google's picker is a script the page loads, so it is stubbed at the window.
 *
 * What is under test is the part that is ours: the credentials reach the right
 * builder calls — above all the app id, without which the chosen file is not
 * added to the grant — and a cancelled picker is a null, not an error.
 */
function stubPicker(respond: (callback: (data: unknown) => void) => void) {
  const calls: Record<string, unknown> = {}

  class Builder {
    addView(view: unknown) { calls.view = view; return this }
    setOAuthToken(token: string) { calls.token = token; return this }
    setDeveloperKey(key: string) { calls.key = key; return this }
    setAppId(id: string) { calls.appId = id; return this }
    setLocale(locale: string) { calls.locale = locale; return this }
    setCallback(callback: (data: unknown) => void) { calls.callback = callback; return this }
    build() {
      return { setVisible: () => respond(calls.callback as (data: unknown) => void) }
    }
  }

  window.google = {
    picker: {
      PickerBuilder: Builder as never,
      DocsView: class { constructor(public id: unknown) {} } as never,
      ViewId: { SPREADSHEETS: 'SPREADSHEETS' },
      Action: { PICKED: 'picked', CANCEL: 'cancel' },
    },
  }

  return calls
}

const credentials = { access_token: 'ya29.x', developer_key: 'AIza', app_id: '123456789012' }

afterEach(() => {
  delete window.google
  vi.restoreAllMocks()
})

describe('the file picker', () => {
  it('hands Google the token, the key and the project number', async () => {
    const calls = stubPicker((cb) => cb({ action: 'picked', docs: [{ id: 'sheet-1', name: 'Leads' }] }))

    const file = await pickSpreadsheet(credentials, 'ar')

    expect(file).toEqual({ id: 'sheet-1', name: 'Leads' })
    expect(calls.token).toBe('ya29.x')
    expect(calls.key).toBe('AIza')
    // The one that matters: without it the file is returned and not granted.
    expect(calls.appId).toBe('123456789012')
    expect(calls.locale).toBe('ar')
  })

  it('only offers spreadsheets', async () => {
    const calls = stubPicker((cb) => cb({ action: 'cancel' }))

    await pickSpreadsheet(credentials, 'en')

    expect((calls.view as { id: unknown }).id).toBe('SPREADSHEETS')
  })

  it('treats cancelling as nothing, not as a failure', async () => {
    stubPicker((cb) => cb({ action: 'cancel' }))

    await expect(pickSpreadsheet(credentials, 'ar')).resolves.toBeNull()
  })

  it('ignores the intermediate loaded event rather than resolving on it', async () => {
    stubPicker((cb) => {
      cb({ action: 'loaded' })
      cb({ action: 'picked', docs: [{ id: 'sheet-2', name: 'Second' }] })
    })

    await expect(pickSpreadsheet(credentials, 'ar')).resolves.toEqual({ id: 'sheet-2', name: 'Second' })
  })
})
