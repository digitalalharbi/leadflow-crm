import { expect, test } from '@playwright/test'
import { ALPHA, LEADS } from '../fixtures/data'
import { openLeadByName, signInAs } from '../fixtures/pages'

/**
 * The files a project sells with are reachable from the interface.
 *
 * This is a wiring test more than a behaviour one, and that is the point. The
 * whole feature — a chooser that reads a lead's own answers, a per-lead link
 * whose opens feed the engagement score, six endpoints and twenty-five backend
 * tests — shipped with no screen calling any of it. A workspace could not
 * upload a brochure, so the chooser had nothing to choose and the automation
 * that sends one had nothing to send. Every one of those tests passed.
 *
 * What was missing was somebody checking that a person could get to it, which
 * is the only thing these two assert.
 */
test.describe('Project files', () => {
  test.beforeEach(async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
  })

  test('a project has somewhere to put the material it sells with', async ({ page }) => {
    await page.goto('/projects')
    await page.getByRole('link', { name: ALPHA.projects[0] }).first().click()
    await page.getByTestId('tab-files').click()

    await expect(page.getByTestId('upload-project-file')).toBeVisible()

    // The empty state names the absence rather than showing a bare panel.
    await expect(page.getByTestId('project-files-empty')).toBeVisible()
  })

  test('a lead screen offers the file that suits them', async ({ page }) => {
    await openLeadByName(page, LEADS.activity)

    /*
     * Either state is a pass: the fixtures carry no project files, so this is
     * the "no files yet" case, and it must say which of the two reasons it is —
     * a lead with no project and a project with no files send you to different
     * people. What must never happen is the card not being there at all.
     */
    await expect(
      page.getByTestId('lead-files').or(page.getByTestId('lead-files-empty')),
    ).toBeVisible()
  })
})
