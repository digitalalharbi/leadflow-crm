import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithProviders } from '@/test/utils'
import { DEFAULT_DESIGN, DEFAULT_SETTINGS, type FormField, type PublicLanding } from '@/features/hub'
import { LeadPage } from './LeadPage'

const submit = vi.fn()
const landing = vi.fn()

vi.mock('@/features/publicLanding', () => ({
  fetchLanding: (...a: unknown[]) => landing(...a),
  submitLanding: (...a: unknown[]) => submit(...a),
}))

const f = (key: string, over: Partial<FormField> = {}): FormField => ({ key, type: 'text', label: key, required: false, hidden: false, mapping: 'none', ...over })

const FIELDS: FormField[] = [
  f('name', { label: 'الاسم', required: true, mapping: 'lead.full_name' }),
  f('mobile', { type: 'phone', label: 'رقم الجوال', required: true, mapping: 'lead.phone' }),
  f('purpose', { type: 'radio', label: 'الغرض', mapping: 'lead.purchase_purpose', options: [{ value: 'residence', label: 'سكن' }, { value: 'investment', label: 'استثمار' }] }),
  f('yield', { label: 'العائد المتوقع', show_when: { match: 'all', conditions: [{ field: 'purpose', op: 'equals', value: 'investment' }] } }),
  f('source', { type: 'hidden', label: 'المصدر', hidden: true, mapping: 'tracking.source', default: '@source' }),
]

const layoutOf = (keys: string[][], steps?: string[][][]) => ({
  sections: (steps ?? [keys.map((k) => [k[0]])]).map((rows, i) => ({
    id: `s${i + 1}`, title: null, description: null, step_title: null, show_when: null, hide_when: null,
    rows: rows.map((cols, ri) => ({ id: `r${i}${ri}`, show_when: null, hide_when: null, columns: cols.map((k, ci) => ({ id: `c${ci}`, fields: [k] })) })),
  })),
})

const page = (over: Partial<PublicLanding['landing']> = {}): PublicLanding => ({
  landing: {
    token: 'abc123', version: 1, headline: 'فلل الياسمين', description: null, benefits: ['قرب من المدارس'], cta_label: null, logo_url: null, image_url: null,
    thank_you: { title: null, message: null }, project: { name: 'الياسمين' }, steps: 1,
    consent: { required: true, text: null, privacy_url: null }, tags: [],
    fields: FIELDS, layout: layoutOf([], [FIELDS.filter((x) => !x.hidden).map((x) => [x.key])]), design: DEFAULT_DESIGN, settings: { mode: 'single', default_country: 'SA', thanks: DEFAULT_SETTINGS.thanks, gtm: { events: true } },
    ...over,
  },
  tracking: { visitor_id: 'v1', session_id: 's1', view_event_id: 'e0', datalayer: { event: 'landing_viewed', event_id: 'e0' } },
})

const done = { ok: true, duplicate: false, event_id: 'ev', thank_you: { title: null, message: null, actions: [], redirect_url: null, redirect_delay: 5 }, datalayer: { event_id: 'ev', source: 'meta' } }
const open = () => renderWithProviders(<LeadPage token="abc123" search="?utm_source=facebook&fbclid=FB1" />)

beforeEach(() => {
  localStorage.clear()
  submit.mockReset().mockResolvedValue(done)
  landing.mockReset().mockResolvedValue(page())
  ;(window as unknown as { dataLayer?: unknown[] }).dataLayer = []
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue({}))
})

const fill = async (user: ReturnType<typeof userEvent.setup>) => {
  await user.type(await screen.findByTestId('input-name'), 'سعد')
  await user.type(screen.getByTestId('input-mobile'), '0501234567')
}

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

  it.each(['ScCid', 'scCid', 'sccid'])('hears Snapchat under one name when the address says %s, and leaves the rest of the address alone', async (spelling) => {
    renderWithProviders(<LeadPage token="abc123" search={`?${spelling}=SN1&utm_source=Snap&Keep=Case`} />)
    await screen.findByTestId('landing-form')

    expect(landing).toHaveBeenCalledWith('abc123', expect.objectContaining({ sccid: 'SN1', utm_source: 'Snap' }))
    const [, asked] = landing.mock.calls.at(-1)!
    expect(Object.keys(asked as object).filter((k) => k.toLowerCase() === 'sccid')).toEqual(['sccid'])
    expect(asked).not.toHaveProperty('keep')
  })

  it('asks with all nine click ids, whatever case they came in, and only those', async () => {
    const ids = { GCLID: 'a', gbraid: 'b', WbRaId: 'c', FbClId: 'd', TTCLID: 'e', ScCid: 'f', MsClkId: 'g', LI_FAT_ID: 'h', TwClId: 'i' }
    renderWithProviders(<LeadPage token="abc123" search={`?${new URLSearchParams({ ...ids, gclid2: 'no' })}`} />)
    await screen.findByTestId('landing-form')

    expect(landing.mock.calls.at(-1)![1]).toEqual({ gclid: 'a', gbraid: 'b', wbraid: 'c', fbclid: 'd', ttclid: 'e', sccid: 'f', msclkid: 'g', li_fat_id: 'h', twclid: 'i' })
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

    await fill(user)
    await user.click(screen.getByTestId('landing-submit'))
    expect(await screen.findByTestId('landing-consent-error')).toBeInTheDocument()
    expect(submit).not.toHaveBeenCalled()
  })

  it('sends the answers once — the phone with its country, the click ids, the consent, only what was shown — and shows the thanks', async () => {
    const user = userEvent.setup()
    open()
    await fill(user)
    await user.click(screen.getByTestId('landing-consent'))
    await user.click(screen.getByTestId('landing-submit'))

    await screen.findByTestId('landing-thanks')
    expect(submit).toHaveBeenCalledTimes(1)
    const [token, body] = submit.mock.calls[0]
    expect(token).toBe('abc123')
    expect(body).toMatchObject({ consent: true, visitor_id: 'v1', session_id: 's1', click_ids: { fbclid: 'FB1' }, utm_source: 'facebook', answers: { name: 'سعد', mobile: { country: 'SA', number: '0501234567' } } })
    expect(body.submission_id).toMatch(/^[0-9a-f-]{36}$/)
    expect(body.answers).not.toHaveProperty('yield')
    expect(body.answers).not.toHaveProperty('source')
    expect(body.answers).not.toHaveProperty('website')
  })

  it('announces the conversion only after the server has kept the lead, with no customer in it', async () => {
    const user = userEvent.setup()
    let resolve: (v: typeof done) => void = () => undefined
    submit.mockReturnValue(new Promise((r) => { resolve = r }))
    open()
    await fill(user)
    await user.click(screen.getByTestId('landing-consent'))
    await user.click(screen.getByTestId('landing-submit'))

    const layer = () => (window as unknown as { dataLayer: { event?: string }[] }).dataLayer.map((e) => e.event)
    await waitFor(() => expect(layer()).toContain('form_submit'))
    expect(layer()).not.toContain('form_success')

    resolve(done)
    await screen.findByTestId('landing-thanks')
    expect(layer()).toContain('form_success')
    const success = (window as unknown as { dataLayer: Record<string, unknown>[] }).dataLayer.find((e) => e.event === 'form_success')!
    expect(success.event_id).toBe('ev')
    expect(JSON.stringify(success)).not.toMatch(/سعد|0501234567/)
  })

  it('keeps the same submission id when the network failed and the visitor tries again — and keeps what they typed', async () => {
    const user = userEvent.setup()
    submit.mockRejectedValueOnce(new Error('offline'))
    open()
    await fill(user)
    await user.click(screen.getByTestId('landing-consent'))
    await user.click(screen.getByTestId('landing-submit'))
    await screen.findByTestId('landing-failed')
    expect(screen.getByTestId('input-name')).toHaveValue('سعد')
    await user.click(screen.getByTestId('landing-submit'))
    await screen.findByTestId('landing-thanks')

    expect(submit).toHaveBeenCalledTimes(2)
    expect(submit.mock.calls[1][1].submission_id).toBe(submit.mock.calls[0][1].submission_id)
  })

  it('resumes where the visitor left off, and forgets once the lead is kept', async () => {
    const user = userEvent.setup()
    const first = open()
    await user.type(await screen.findByTestId('input-name'), 'سعد')
    first.unmount()

    open()
    expect(await screen.findByTestId('input-name')).toHaveValue('سعد')

    await user.type(screen.getByTestId('input-mobile'), '0501234567')
    await user.click(screen.getByTestId('landing-consent'))
    await user.click(screen.getByTestId('landing-submit'))
    await screen.findByTestId('landing-thanks')
    expect(localStorage.getItem('mt:form:abc123:1')).toBeNull()
  })

  it('walks a multi-step form one step at a time, with progress, and reports each finished step', async () => {
    const user = userEvent.setup()
    const fields = [f('name', { label: 'الاسم', required: true, mapping: 'lead.full_name' }), f('mobile', { type: 'phone', label: 'رقم الجوال', required: true, mapping: 'lead.phone' })]
    landing.mockResolvedValue(page({ fields, steps: 2, layout: layoutOf([], [[['name']], [['mobile']]]), settings: { ...page().landing.settings, mode: 'multi' } }))
    open()
    await screen.findByTestId('landing-step')

    expect(screen.queryByTestId('input-mobile')).toBeNull()
    expect(screen.getByTestId('form-progress')).toHaveAttribute('aria-valuenow', '1')
    await user.click(screen.getByTestId('landing-submit'))
    expect(screen.queryByTestId('input-mobile')).toBeNull()
    await user.type(screen.getByTestId('input-name'), 'سعد')
    await user.click(screen.getByTestId('landing-submit'))
    await waitFor(() => expect(screen.getByTestId('input-mobile')).toBeInTheDocument())
    expect(screen.getByTestId('form-progress')).toHaveAttribute('aria-valuenow', '2')
    expect(submit).not.toHaveBeenCalled()

    const sent = (vi.mocked(fetch).mock.calls as unknown[][]).map((c) => JSON.parse(String((c[1] as RequestInit).body)))
    expect(sent.map((b) => b.event)).toEqual(['form_start', 'form_step'])
    expect(sent[1].meta).toEqual({ step: '1' })
    expect(await screen.findByTestId('landing-back')).toBeInTheDocument()
  })

  it('says a page that is not there is not available — one answer for every reason', async () => {
    landing.mockRejectedValue(new Error('404'))
    open()

    expect(await screen.findByTestId('landing-unavailable')).toBeInTheDocument()
  })

  it('shows what the server refused beside the field it was about, and goes back to the step it was on', async () => {
    const user = userEvent.setup()
    const { ApiError } = await import('@/lib/api')
    submit.mockRejectedValueOnce(new ApiError('x', 422, { 'answers.mobile': ['invalid_phone'] }))
    open()
    await fill(user)
    await user.click(screen.getByTestId('landing-consent'))
    await user.click(screen.getByTestId('landing-submit'))

    expect(await within(await screen.findByTestId('field-mobile')).findByRole('alert')).toBeInTheDocument()
  })
})
