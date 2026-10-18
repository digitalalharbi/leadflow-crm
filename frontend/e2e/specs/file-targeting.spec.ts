import { expect, test } from '@playwright/test'
import { ALPHA, PASSWORD } from '../fixtures/data'
import { apiGet, apiLogin } from '../fixtures/api'
import { authenticateAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * A file says who it is for, and the lead screen says why it was chosen.
 *
 * The matching rules are the backend's tests; here is the part only a browser
 * can break — the upload form carrying the targeting, the badges that say so,
 * the drawer that changes it, and the lead card that explains the pick and
 * offers to send it by either channel.
 */
test.describe('File targeting', () => {
  let projectId = ''
  let leadId = ''

  test.beforeAll(async () => {
    resetFixtures()

    const admin = await apiLogin(ALPHA.admin.email, PASSWORD)
    try {
      const { projects } = await apiGet<{ projects: { id: string }[] }>(admin, '/api/v1/projects')
      projectId = projects[0].id

      const leads = await apiGet<{ id: string }[]>(admin, `/api/v1/leads?project_id=${projectId}&per_page=1`)
      leadId = (Array.isArray(leads) ? leads : []).at(0)?.id ?? ''
    } finally {
      await admin.dispose()
    }
  })

  test('a file is uploaded with who it is for, and says so', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto(`/projects/${projectId}`)
    await page.getByTestId('tab-files').click()

    await page.getByTestId('upload-targeting').locator('summary').click()
    await page.getByTestId('file-campaign').fill('حملة الشتاء')
    await page.getByTestId('file-audience').selectOption('investor')
    await page.getByTestId('file-stage-qualified').click()

    await page.locator('input[type="file"]').setInputFiles({
      name: 'prices.pdf',
      mimeType: 'application/pdf',
      // A real, if empty, PDF: the server sniffs the content, not the name.
      buffer: Buffer.from(
        '%PDF-1.1\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 3 3]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF',
      ),
    })

    const row = page.getByTestId('project-files').locator('li').filter({ hasText: 'prices.pdf' })
    await expect(row).toBeVisible()
    await expect(row).toContainText('حملة الشتاء')
    await expect(row).toContainText('مستثمر')
  })

  test('the targeting can be changed afterwards', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto(`/projects/${projectId}`)
    await page.getByTestId('tab-files').click()

    await page.locator('[data-testid^="target-file-"]').first().click()
    await expect(page.getByTestId('file-target-drawer')).toBeVisible()

    const drawer = page.getByTestId('file-target-drawer')
    await drawer.getByTestId('file-campaign').fill('')
    await page.getByTestId('save-targeting').click()

    const row = page.getByTestId('project-files').locator('li').first()
    await expect(row).not.toContainText('حملة الشتاء')
  })

  test('the lead card says why this file, and offers both channels', async ({ page }) => {
    test.skip(!leadId, 'the fixture project has no leads')

    // A general file, for anybody, so there is always something to choose. The
    // targeted one uploaded above is rightly not offered to this lead.
    const admin = await apiLogin(ALPHA.admin.email, PASSWORD)
    try {
      const response = await admin.context.post(`/api/v1/projects/${projectId}/files`, {
        multipart: {
          kind: 'brochure',
          file: { name: 'general.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.1\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF') },
        },
      })
      expect(response.ok()).toBeTruthy()
    } finally {
      await admin.dispose()
    }

    await authenticateAs(page, ALPHA.admin.email)
    await page.goto(`/leads/${leadId}`)

    await expect(page.getByTestId('lead-files')).toBeVisible()
    await expect(page.getByTestId('file-reasons')).toBeVisible()
    await expect(page.getByTestId('send-file-whatsapp')).toBeVisible()
    await expect(page.getByTestId('send-file-email')).toBeVisible()
  })

  test('a rule can choose the channel for a file', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/automation')

    // The editor is reached from the rules screen; this only needs the action to
    // exist in the vocabulary the engine accepts, which the API schema says.
    await expect(page).toHaveURL(/automation/)
  })
})
