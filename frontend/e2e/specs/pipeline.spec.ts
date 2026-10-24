import { expect, test, type Page } from '@playwright/test'
import { ALPHA, LEADS } from '../fixtures/data'
import { apiGet, apiLogin } from '../fixtures/api'
import { signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

interface LeadRow { id: string; full_name: string; status: string }

async function leadStatus(name: string): Promise<string> {
  const session = await apiLogin(ALPHA.admin.email)

  try {
    const leads = await apiGet<LeadRow[]>(session, `/api/v1/leads?search=${encodeURIComponent(name)}`)
    return leads.find((l) => l.full_name === name)?.status ?? 'missing'
  } finally {
    await session.dispose()
  }
}

function column(page: Page, status: string) {
  return page.getByTestId(`pipeline-column-${status}`)
}

/**
 * dnd-kit listens for pointer events and needs movement past its activation
 * distance before it treats a gesture as a drag, so a plain dragTo does nothing.
 */
async function dragCardToColumn(page: Page, cardName: string, targetStatus: string) {
  const card = page.getByTestId('pipeline-card').filter({ hasText: cardName }).first()
  const target = column(page, targetStatus)

  // The board sits below the pipeline's picture, so bring it into the
  // viewport first: pointer coordinates outside the window go nowhere.
  await target.evaluate((el) => el.scrollIntoView({ block: 'start' }))
  await card.scrollIntoViewIfNeeded()

  const from = await card.boundingBox()
  const to = await target.boundingBox()

  if (!from || !to) throw new Error('Could not locate the card or the target column')

  await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2)
  await page.mouse.down()
  // Several small steps: one jump can land outside the droppable's hit test.
  await page.mouse.move(from.x + from.width / 2 + 20, from.y + from.height / 2 + 10, { steps: 5 })
  await page.mouse.move(to.x + to.width / 2, to.y + 140, { steps: 15 })
  await page.mouse.move(to.x + to.width / 2, to.y + 150, { steps: 5 })
  await page.mouse.up()
}

test.describe('Pipeline', () => {
  test.beforeEach(async ({ page }) => {
    resetFixtures()
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/pipeline')
    await expect(page.getByRole('heading', { name: 'مسار البيع', level: 1 })).toBeVisible()
  })

  test('draws the pipeline as a picture before the board', async ({ page }) => {
    const hero = page.getByTestId('pipeline-hero')
    await expect(hero).toBeVisible()

    // Five numbers, the funnel, both donuts and the two watchlists.
    for (const id of ['kpi-open-value', 'kpi-hot', 'kpi-stalled', 'kpi-response', 'kpi-win-rate', 'pipeline-funnel', 'stage-donut', 'quality-donut', 'team-workload']) {
      await expect(hero.getByTestId(id)).toBeVisible()
    }

    // The funnel only narrows: each count is no larger than the one before.
    const counts = await hero.getByTestId('pipeline-funnel').locator('li').evaluateAll((items) =>
      items.map((li) => Number(li.getAttribute('data-count'))),
    )
    expect(counts.length).toBe(7)
    for (let i = 1; i < counts.length; i++) expect(counts[i]).toBeLessThanOrEqual(counts[i - 1])

    // A column header carries its own figures.
    await expect(page.getByTestId('stage-stats-contacted')).toBeVisible()
  })

  test('shows every stage as its own column with a total', async ({ page }) => {
    for (const status of ['new', 'contacted', 'qualified', 'appointment', 'won', 'lost']) {
      await expect(column(page, status)).toBeVisible()
    }
  })

  test('dragging a lead moves it in the UI, the backend and the timeline', async ({ page }) => {
    expect(await leadStatus(LEADS.drag)).toBe('new')

    const newColumn = column(page, 'new')
    const contactedColumn = column(page, 'contacted')

    await expect(newColumn.getByText(LEADS.drag)).toBeVisible()

    const newTotalBefore = Number((await newColumn.getByTestId('column-total').textContent()) ?? '0')
    const contactedTotalBefore = Number(
      (await contactedColumn.getByTestId('column-total').textContent()) ?? '0',
    )

    await dragCardToColumn(page, LEADS.drag, 'contacted')

    // 1. The card is in the new column…
    await expect(contactedColumn.getByText(LEADS.drag)).toBeVisible()
    await expect(newColumn.getByText(LEADS.drag)).toHaveCount(0)

    // 2. …the totals moved with it…
    await expect(newColumn.getByTestId('column-total')).toHaveText(String(newTotalBefore - 1))
    await expect(contactedColumn.getByTestId('column-total')).toHaveText(String(contactedTotalBefore + 1))

    // 3. …the backend agrees…
    await expect(async () => {
      expect(await leadStatus(LEADS.drag)).toBe('contacted')
    }).toPass({ timeout: 10_000 })

    // 4. …and the move is on the lead's timeline.
    await page.goto('/leads')
    await page.getByTestId('lead-search').fill(LEADS.drag)
    await page.getByRole('link', { name: LEADS.drag }).click()

    await expect(page.getByTestId('activity-timeline').getByText(/→\s*تم التواصل/)).toBeVisible()
  })

  test('dropping into Lost asks for a reason before committing', async ({ page }) => {
    await dragCardToColumn(page, LEADS.drag, 'lost')

    const dialog = page.getByRole('dialog')
    await expect(dialog).toBeVisible()
    await expect(dialog.getByRole('button', { name: 'تأكيد' })).toBeDisabled()

    // Cancelling must leave the lead exactly where it was.
    await dialog.getByRole('button', { name: 'إلغاء' }).click()
    expect(await leadStatus(LEADS.drag)).toBe('new')

    await dragCardToColumn(page, LEADS.drag, 'lost')
    await page.getByRole('dialog').getByLabel('سبب الخسارة').selectOption({ index: 1 })
    await page.getByRole('dialog').getByRole('button', { name: 'تأكيد' }).click()

    await expect(async () => {
      expect(await leadStatus(LEADS.drag)).toBe('lost')
    }).toPass({ timeout: 10_000 })
  })

  test('a card links through to the lead', async ({ page }) => {
    await column(page, 'new').getByText(LEADS.drag).click()

    await expect(page).toHaveURL(/\/leads\/[0-9a-f-]{36}/)
    await expect(page.getByRole('heading', { name: LEADS.drag })).toBeVisible()
  })
})
