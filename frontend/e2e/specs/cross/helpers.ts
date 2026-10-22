import { expect, type Page } from '@playwright/test'
import { apiLogin } from '../../fixtures/api'

/** The page is no wider than the screen: nothing a thumb has to drag sideways to reach. */
export async function expectNoSidewaysScroll(page: Page, where: string) {
  const { scroll, client } = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth }))

  expect(scroll, `${where}: the page scrolls sideways (${scroll} > ${client})`).toBeLessThanOrEqual(client + 1)
}

/** Errors the page throws — the thing every browser reports differently and a user never sees until it breaks. */
export function collectPageErrors(page: Page): string[] {
  const errors: string[] = []

  page.on('pageerror', (error) => errors.push(error.message))

  return errors
}

/** A lead the admin can open, by its public id. */
export async function firstLeadId(email: string): Promise<string> {
  const session = await apiLogin(email)

  try {
    const response = await session.context.get('/api/v1/leads?per_page=1')
    const body = (await response.json()) as { data: { id: string }[] | { leads?: { id: string }[] } }
    const rows = Array.isArray(body.data) ? body.data : (body.data.leads ?? [])

    return rows[0].id
  } finally {
    await session.dispose()
  }
}

/** A live report link the way a stranger would open it. */
export async function makeSharedReport(email: string, name = 'تقرير للمتصفحات'): Promise<{ id: string; token: string }> {
  const session = await apiLogin(email)

  try {
    const made = await session.context.post('/api/v1/report-shares', { data: { name, preset: 'executive_management' } })
    const share = ((await made.json()) as { data: { share: { id: string; url: string } } }).data.share

    return { id: share.id, token: share.url.split('/').pop()! }
  } finally {
    await session.dispose()
  }
}
