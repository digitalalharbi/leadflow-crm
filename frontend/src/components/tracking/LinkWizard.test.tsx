import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithProviders } from '@/test/utils'
import { LinkWizard } from './LinkWizard'

const createLink = vi.fn()

vi.mock('@/features/leads', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/leads')>()),
  fetchProjects: () => Promise.resolve([{ id: 'p1', name: 'الياسمين' }]),
}))
vi.mock('@/features/properties', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/properties')>()),
  fetchProjectProperties: () => Promise.resolve({ properties: [], summary: {} }),
}))
vi.mock('@/features/tracking', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/tracking')>()),
  fetchAttributionOptions: () => Promise.resolve({ campaigns: [{ id: 'c1', name: 'Summer Launch' }], ads: [], creatives: [], partners: [], reps: [], platforms: [] }),
  createLink: (...a: unknown[]) => createLink(...a),
}))
vi.mock('@/features/hub', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/hub')>()),
  fetchForms: () => Promise.resolve([{ id: 'f1', name: 'نموذج الاهتمام', status: 'published', fields: [], steps: 1 }]),
  fetchDestinations: () => Promise.resolve([{ id: 'd1', name: 'GA4 الرئيسي', is_enabled: true, readiness: 'ready', project: null }]),
}))

const made = { id: 'l1', token: 'tok123', url: 'https://mohtam.test/t/tok123', landing_url: 'https://mohtam.test/l/tok123', name: 'x' }

const setup = () => renderWithProviders(<LinkWizard open onClose={vi.fn()} onAnalytics={vi.fn()} onQr={vi.fn()} />)

beforeEach(() => createLink.mockReset().mockResolvedValue({ link: made }))

async function toDestination(user: ReturnType<typeof userEvent.setup>) {
  await screen.findByTestId('wizard-step-project')
  await waitFor(() => expect(screen.getByRole('option', { name: 'الياسمين' })).toBeInTheDocument())
  await user.selectOptions(screen.getByTestId('wizard-project'), 'p1')
  await user.click(screen.getByTestId('wizard-next'))
  await user.click(screen.getByTestId('wizard-channel-meta'))
  await waitFor(() => expect(screen.getByRole('option', { name: 'Summer Launch' })).toBeInTheDocument())
  await user.selectOptions(screen.getByTestId('wizard-campaign'), 'c1')
  await user.click(screen.getByTestId('wizard-next'))
}

describe('the link builder', () => {
  it('opens on the project and will not go on without one', async () => {
    setup()
    await screen.findByTestId('wizard-step-project')

    expect(screen.getByTestId('wizard-next')).toBeDisabled()
    expect(screen.getByTestId('wizard-steps').querySelectorAll('li')).toHaveLength(5)
  })

  it('suggests a name from the project, the channel and the campaign', async () => {
    const user = userEvent.setup()
    setup()
    await toDestination(user)
    await user.click(screen.getByTestId('wizard-back'))

    expect(screen.getByTestId('wizard-name')).toHaveAttribute('placeholder', expect.stringContaining('الياسمين'))
    expect(screen.getByTestId('wizard-name')).toHaveAttribute('placeholder', expect.stringContaining('Summer Launch'))
  })

  it('a landing page needs a form; a redirect needs an address', async () => {
    const user = userEvent.setup()
    setup()
    await toDestination(user)

    expect(screen.getByTestId('wizard-next')).toBeDisabled()
    await user.selectOptions(screen.getByTestId('wizard-form'), 'f1')
    expect(screen.getByTestId('wizard-next')).toBeEnabled()

    await user.click(screen.getByTestId('wizard-mode-redirect'))
    expect(screen.getByTestId('wizard-next')).toBeDisabled()
    await user.type(screen.getByTestId('wizard-destination-url'), 'https://example.sa/p')
    expect(screen.getByTestId('wizard-next')).toBeEnabled()
  })

  it('fills the UTMs a platform expects, and the campaign from its name', async () => {
    const user = userEvent.setup()
    setup()
    await toDestination(user)
    await user.selectOptions(screen.getByTestId('wizard-form'), 'f1')
    await user.click(screen.getByTestId('wizard-next'))

    expect(screen.getByTestId('wizard-utm-source')).toHaveValue('facebook')
    expect(screen.getByTestId('wizard-utm-medium')).toHaveValue('paid_social')
    expect(screen.getByTestId('wizard-utm-campaign')).toHaveValue('summer_launch')
    expect(await screen.findByTestId('wizard-destinations')).toHaveTextContent('GA4 الرئيسي')
  })

  it('holds a bad custom parameter name back with a reason', async () => {
    const user = userEvent.setup()
    setup()
    await toDestination(user)
    await user.selectOptions(screen.getByTestId('wizard-form'), 'f1')
    await user.click(screen.getByTestId('wizard-next'))
    await user.click(screen.getByTestId('wizard-add-param'))
    await user.type(within0('wizard-param-0')[0], 'bad name')

    expect(screen.getByTestId('wizard-next')).toBeDisabled()
    expect(screen.getByRole('alert')).toBeInTheDocument()
  })

  it('creates exactly what was reviewed, then hands over the link with what to do next', async () => {
    const user = userEvent.setup()
    setup()
    await toDestination(user)
    await user.selectOptions(screen.getByTestId('wizard-form'), 'f1')
    await user.click(screen.getByTestId('wizard-next'))
    await user.click(screen.getByTestId('wizard-add-param'))
    await user.type(within0('wizard-param-0')[0], 'agent_code')
    await user.type(within0('wizard-param-0')[1], 'A7')
    await user.click(screen.getByTestId('wizard-next'))

    expect(screen.getByTestId('review-url')).toHaveTextContent('utm_source=facebook')
    expect(screen.getByTestId('review-url')).toHaveTextContent('agent_code=A7')
    await user.click(screen.getByTestId('wizard-create'))

    await screen.findByTestId('wizard-made')
    expect(createLink).toHaveBeenCalledWith(expect.objectContaining({
      project_id: 'p1', campaign_id: 'c1', channel: 'meta', mode: 'landing', landing_form_id: 'f1', destination_url: null,
      utm_source: 'facebook', utm_medium: 'paid_social', utm_campaign: 'summer_launch', custom_params: { agent_code: 'A7' },
    }))
    expect(screen.getByTestId('wizard-url')).toHaveTextContent('https://mohtam.test/t/tok123')
    for (const id of ['wizard-copy', 'wizard-qr', 'wizard-preview', 'wizard-test', 'wizard-analytics']) expect(screen.getByTestId(id)).toBeInTheDocument()
  })
})

/** The two inputs of a custom-parameter row. */
function within0(testId: string): HTMLElement[] {
  return Array.from(screen.getByTestId(testId).querySelectorAll('input'))
}
