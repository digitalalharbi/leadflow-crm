import { expect, test } from '@playwright/test'
import { ALPHA, PASSWORD } from '../fixtures/data'
import { apiGet, apiLogin } from '../fixtures/api'
import { authenticateAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * A project asks its own questions; the answers have consequences.
 *
 * Driven through the screens a manager and a rep actually use: write a question
 * with a rule on the project, answer it on a lead of that project, and see the
 * answer kept. The scoring arithmetic itself is the backend's tests; what is
 * asserted here is that the form can say what the backend needs to hear, and
 * that the server's refusals reach the person who typed the mistake.
 */
test.describe('Project questions', () => {
  let projectId = ''
  let leadId = ''

  // Put the fixtures back: later specs rely on a project with no files and no
  // questions, and a spec that leaves its own behind makes them fail for it.
  test.afterAll(() => resetFixtures())

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

  test('a manager writes a question with a rule and it appears in the list', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto(`/projects/${projectId}`)
    await page.getByTestId('tab-questions').click()

    await page.getByTestId('add-question').click()
    await page.getByTestId('question-label').fill('هل الشراء لعائلة؟')
    await page.getByTestId('add-rule').click()
    await page.getByTestId('rule-points').fill('10')
    await page.getByTestId('rule-effect').selectOption('priority')
    await page.getByTestId('save-question').click()

    await expect(page.getByTestId('question-list')).toContainText('هل الشراء لعائلة؟')
    await expect(page.getByTestId('question-list')).toContainText('1')
  })

  test('a rule that could never fire is refused, and the person is told why', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto(`/projects/${projectId}`)
    await page.getByTestId('tab-questions').click()

    await page.getByTestId('add-question').click()
    await page.getByTestId('question-label').fill('نوع الوحدة')
    await page.getByTestId('question-type').selectOption('select')

    // A rule naming a choice that does not exist.
    await page.getByTestId('add-rule').click()
    await page.getByTestId('save-question').click()

    await expect(page.getByTestId('question-error')).toBeVisible()
  })

  test('the answers appear on a lead of that project and are kept', async ({ page }) => {
    test.skip(!leadId, 'the fixture project has no leads')

    await authenticateAs(page, ALPHA.admin.email)
    await page.goto(`/leads/${leadId}`)

    const card = page.getByTestId('project-answers')
    await expect(card).toBeVisible()

    // The key is made from the label, so the field is found by what it is.
    const answer = card.locator('[data-testid^="answer-"]').first()
    await answer.selectOption('true')
    await page.getByTestId('save-answers').click()
    await expect(page.getByText('تم الحفظ.')).toBeVisible()

    await page.reload()
    await expect(page.getByTestId('project-answers').locator('[data-testid^="answer-"]').first()).toHaveValue('true')
  })

  test('a rep can answer but cannot write questions', async ({ page }) => {
    await authenticateAs(page, ALPHA.rep1.email)
    await page.goto(`/projects/${projectId}`)
    await page.getByTestId('tab-questions').click()

    await expect(page.getByTestId('project-questions')).toBeVisible()
    await expect(page.getByTestId('add-question')).toHaveCount(0)
  })
})
