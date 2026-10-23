import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Route, Routes } from 'react-router-dom'
import { renderWithProviders } from '@/test/utils'
import type { PublicLanding } from '@/features/hub'
import { fieldProblem, LeadPage } from './LeadPage'

const submit = vi.fn()
const landing = vi.fn()

vi.mock('@/features/hub', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/hub')>()),
  fetchLanding: (...a: unknown[]) => landing(...a),
  submitLanding: (...a: unknown[]) => submit(...a),
}))

vi.mock('@/lib/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api')>()),
  http: { post: vi.fn().mockResolvedValue({}) },
}))

const page = (over: Partial<PublicLanding['landing']> = {}): PublicLanding => ({
  landing: {
    token: 'abc123', headline: 'فلل الياسمين', description: null, benefits: ['قرب من المدارس'], cta_label: null, logo_url: null, image_url: null,
    thank_you: { title: null, message: null }, project: { name: 'الياسمين' }, steps: 1,
    consent: { required: true, text: null, privacy_url: null }, tags: [],
    fields: [
      { key: 'name', type: 'text', label: 'الاسم', required: true, hidden: false, mapping: 'lead.full_name', step: 1 },
      { key: 'mobile', type: 'phone', label: 'رقم الجوال', required: true, hidden: false, mapping: 'lead.phone', step: 1 },
      { key: 'purpose', type: 'radio', label: 'الغرض', required: false, hidden: false, mapping: 'lead.purchase_purpose', step: 1, options: [{ value: 'residence', label: 'سكن' }, { value: 'investment', label: 'استثمار' }] },
      { key: 'yield', type: 'text', label: 'العائد المتوقع', required: false, hidden: false, mapping: 'none', step: 1, visible_if: { field: 'purpose', equals: 'investment' } },
      { key: 'source', type: 'hidden', label: 'المصدر', required: false, hidden: true, mapping: 'tracking.source', step: 1, default: '@source' },
    ],
    ...over,
  },
  tracking: { visitor_id: 'v1', session_id: 's1', view_event_id: 'e0', datalayer: { event: 'landing_viewed', event_id: 'e0' } },
})

const open = () => renderWithProviders(<Routes><Route path="/l/:token" element={<LeadPage />} /></Routes>, { route: '/l/abc123?utm_source=facebook&fbclid=FB1' })

beforeEach(() => {
  submit.mockReset().mockResolvedValue(undefined)
  landing.mockReset().mockResolvedValue(page())
})

describe('fieldProblem', () => {
  it.each([
    [{ type: 'text', required: true }, '', 'required'],
    [{ type: 'text', required: false }, '', null],
    [{ type: 'phone', required: true }, '12', 'phone'],
    [{ type: 'phone', required: true }, '+966 50 123 4567', null],
    [{ type: 'email', required: false }, 'nope', 'email'],
    [{ type: 'email', required: false }, 'a@b.sa', null],
    [{ type: 'number', required: false }, 'abc', 'number'],
    [{ type: 'checkbox', required: true }, false, 'required'],
  ] as const)('%j with %j → %s', (shape, value, expected) => {
    expect(fieldProblem({ key: 'k', label: 'K', hidden: false, mapping: 'none', ...shape } as never, value)).toBe(expected)
  })
})

describe('the landing page', () => {
  it('shows the project, the headline, the selling points and the form — never the hidden field', async () => {
    open()

    expect(await screen.findByTestId('landing-headline')).toHaveTextContent('فلل الياسمين')
    expect(screen.getByTestId('landing-benefits')).toHaveTextContent('قرب من المدارس')
    expect(screen.getByTestId('field-name')).toBeInTheDocument()
    expect(screen.queryByTestId('field-source')).toBeNull()
  })

  it('asks the server with what the address carried: the UTMs and the click ids', async () => {
    open()
    await screen.findByTestId('landing-form')

    expect(landing).toHaveBeenCalledWith('abc123', expect.objectContaining({ utm_source: 'facebook', fbclid: 'FB1' }))
  })

  it('shows a conditional question only after the answer that asks for it', async () => {
    const user = userEvent.setup()
    open()
    await screen.findByTestId('landing-form')

    expect(screen.queryByTestId('field-yield')).toBeNull()
    await user.click(screen.getByLabelText('استثمار'))
    expect(screen.getByTestId('field-yield')).toBeInTheDocument()
  })

  it('refuses to send without the required answers or the consent, and says which', async () => {
    const user = userEvent.setup()
    open()
    await user.click(await screen.findByTestId('landing-submit'))

    expect(submit).not.toHaveBeenCalled()
    expect(screen.getAllByRole('alert').length).toBeGreaterThan(0)

    await user.type(screen.getByTestId('field-name'), 'سعد')
    await user.type(screen.getByTestId('field-mobile'), '0501234567')
    await user.click(screen.getByTestId('landing-submit'))
    expect(await screen.findByTestId('landing-consent-error')).toBeInTheDocument()
    expect(submit).not.toHaveBeenCalled()
  })

  it('sends the answers once with one submission id, the click ids and the consent — and shows the thanks', async () => {
    const user = userEvent.setup()
    open()
    await user.type(await screen.findByTestId('field-name'), 'سعد')
    await user.type(screen.getByTestId('field-mobile'), '0501234567')
    await user.click(screen.getByTestId('landing-consent'))
    await user.click(screen.getByTestId('landing-submit'))

    await screen.findByTestId('landing-thanks')
    expect(submit).toHaveBeenCalledTimes(1)
    const [token, body] = submit.mock.calls[0]
    expect(token).toBe('abc123')
    expect(body).toMatchObject({ consent: true, visitor_id: 'v1', session_id: 's1', click_ids: { fbclid: 'FB1' }, utm_source: 'facebook', answers: { name: 'سعد', mobile: '0501234567' } })
    expect(body.submission_id).toMatch(/^[0-9a-f-]{36}$/)
    // A question that was never shown is not an answer.
    expect(body.answers).not.toHaveProperty('yield')
    expect(body.answers).not.toHaveProperty('source')
  })

  it('keeps the same submission id when the network failed and the visitor tries again', async () => {
    const user = userEvent.setup()
    submit.mockRejectedValueOnce(new Error('offline'))
    open()
    await user.type(await screen.findByTestId('field-name'), 'سعد')
    await user.type(screen.getByTestId('field-mobile'), '0501234567')
    await user.click(screen.getByTestId('landing-consent'))
    await user.click(screen.getByTestId('landing-submit'))
    await screen.findByTestId('landing-failed')
    await user.click(screen.getByTestId('landing-submit'))
    await screen.findByTestId('landing-thanks')

    expect(submit).toHaveBeenCalledTimes(2)
    expect(submit.mock.calls[1][1].submission_id).toBe(submit.mock.calls[0][1].submission_id)
  })

  it('walks a multi-step form one step at a time', async () => {
    const user = userEvent.setup()
    landing.mockResolvedValue(page({ steps: 2, fields: [
      { key: 'name', type: 'text', label: 'الاسم', required: true, hidden: false, mapping: 'lead.full_name', step: 1 },
      { key: 'mobile', type: 'phone', label: 'رقم الجوال', required: true, hidden: false, mapping: 'lead.phone', step: 2 },
    ] }))
    open()
    await screen.findByTestId('landing-step')

    expect(screen.queryByTestId('field-mobile')).toBeNull()
    await user.click(screen.getByTestId('landing-submit'))
    expect(screen.queryByTestId('field-mobile')).toBeNull()
    await user.type(screen.getByTestId('field-name'), 'سعد')
    await user.click(screen.getByTestId('landing-submit'))
    await waitFor(() => expect(screen.getByTestId('field-mobile')).toBeInTheDocument())
    expect(submit).not.toHaveBeenCalled()
  })

  it('says a page that is not there is not available — one answer for every reason', async () => {
    landing.mockRejectedValue(new Error('404'))
    open()

    expect(await screen.findByTestId('landing-unavailable')).toBeInTheDocument()
  })
})
