/**
 * Google's file picker, which is how a spreadsheet becomes readable.
 *
 * The product holds `drive.file`, a grant that covers files a person chose and
 * nothing else in their Drive. There is therefore no list to show: the file
 * arrives by id from Google's own picker, which also adds it to the grant. Google
 * loads the picker as a script and renders it in an iframe, so none of the
 * person's file names pass through this application on the way.
 */

export interface PickedFile {
  id: string
  name: string
}

export interface PickerCredentials {
  access_token: string
  developer_key: string
  app_id: string
}

interface PickerResponse {
  action: string
  docs?: Array<{ id: string; name: string }>
}

interface PickerBuilderLike {
  addView(view: unknown): PickerBuilderLike
  setOAuthToken(token: string): PickerBuilderLike
  setDeveloperKey(key: string): PickerBuilderLike
  setAppId(id: string): PickerBuilderLike
  setLocale(locale: string): PickerBuilderLike
  setCallback(callback: (data: PickerResponse) => void): PickerBuilderLike
  build(): { setVisible(visible: boolean): void }
}

interface PickerApi {
  PickerBuilder: new () => PickerBuilderLike
  DocsView: new (viewId: unknown) => unknown
  ViewId: { SPREADSHEETS: unknown }
  Action: { PICKED: string; CANCEL: string }
}

declare global {
  interface Window {
    gapi?: { load(name: string, callback: () => void): void }
    google?: { picker?: PickerApi }
  }
}

const SCRIPT = 'https://apis.google.com/js/api.js'

let loading: Promise<PickerApi> | null = null

/**
 * Loaded on demand and once.
 *
 * Nothing from Google is on the page until somebody presses "choose a file":
 * most visits to this screen never get that far, and a third-party script that
 * loads for everyone is a cost everyone pays for something a few people use.
 * A failed load is forgotten, so pressing the button again tries again.
 */
function load(): Promise<PickerApi> {
  if (window.google?.picker) return Promise.resolve(window.google.picker)

  loading ??= new Promise<PickerApi>((resolve, reject) => {
    const fail = (reason: string) => {
      loading = null
      reject(new Error(reason))
    }

    const script = document.createElement('script')

    script.src = SCRIPT
    script.async = true

    script.addEventListener('error', () => fail('picker_script_blocked'), { once: true })
    script.addEventListener(
      'load',
      () => {
        if (!window.gapi) {
          fail('picker_script_missing')

          return
        }

        window.gapi.load('picker', () => {
          const picker = window.google?.picker

          if (picker) {
            resolve(picker)
          } else {
            fail('picker_unavailable')
          }
        })
      },
      { once: true },
    )

    document.head.appendChild(script)
  })

  return loading
}

/**
 * Opens the picker. Resolves with the chosen file, or null if it was cancelled.
 */
export async function pickSpreadsheet(
  credentials: PickerCredentials,
  locale: string,
): Promise<PickedFile | null> {
  const picker = await load()

  return new Promise((resolve) => {
    new picker.PickerBuilder()
      .addView(new picker.DocsView(picker.ViewId.SPREADSHEETS))
      .setOAuthToken(credentials.access_token)
      .setDeveloperKey(credentials.developer_key)
      // The project number. Without it the file is returned and not added to
      // the grant, and the first read fails with a 404.
      .setAppId(credentials.app_id)
      .setLocale(locale)
      .setCallback((data) => {
        if (data.action === picker.Action.PICKED && data.docs?.[0]) {
          resolve({ id: data.docs[0].id, name: data.docs[0].name })
        } else if (data.action === picker.Action.CANCEL) {
          resolve(null)
        }
      })
      .build()
      .setVisible(true)
  })
}
