import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { DEFAULT_DESIGN, DEFAULT_SETTINGS, type FormField, type FormLayout } from '@/features/hub'
import { FormRenderer, type RenderForm } from './FormRenderer'
import { ThankYou, actionHref } from './ThankYou'

const f = (key: string, over: Partial<FormField> = {}): FormField => ({ key, type: 'text', label: key, required: false, hidden: false, mapping: 'none', ...over })
const layout = (rows: string[][][]): FormLayout => ({ sections: [{ id: 's1', title: null, description: null, step_title: null, show_when: null, hide_when: null, rows: rows.map((cols, i) => ({ id: `r${i}`, show_when: null, hide_when: null, columns: cols.map((k, ci) => ({ id: `c${ci}`, fields: k })) })) }] })

const make = (fields: FormField[], rows: string[][][], over: Partial<RenderForm> = {}): RenderForm => ({
  headline: 'العنوان', description: null, benefits: [], logo_url: null, image_url: null, project: { name: 'مشروع' }, cta_label: null,
  fields, layout: layout(rows), design: DEFAULT_DESIGN, settings: { ...DEFAULT_SETTINGS, mode: 'single' }, consent: { required: false, text: null, privacy_url: null }, ...over,
})

const MATRIX = f('priorities', { type: 'matrix', label: 'ما أهمية…', matrix: { rows: [{ value: 'location', label: 'الموقع' }, { value: 'price', label: 'السعر' }], columns: [{ value: 'low', label: 'غير مهم' }, { value: 'high', label: 'مهم جدًا' }], multiple: false, require_all_rows: true, min_answers: 0 } })
const REPEATER = f('guests', { type: 'repeater', label: 'المرافقون', repeater: { subfields: [{ key: 'name', type: 'text', label: 'الاسم', required: true }, { key: 'mobile', type: 'phone', label: 'الجوال' }], min_rows: 0, max_rows: 2, default_rows: 1, add_label: null } })

describe('rows and columns', () => {
  it('draws a row of two columns as two columns, and one of them alone as a single column', () => {
    render(<FormRenderer form={make([f('a'), f('b'), f('c')], [[['a'], ['b']], [['c']]])} preview />)

    expect(screen.getByTestId('row-r0').className).toContain('@md:grid-cols-2')
    expect(screen.getByTestId('row-r1').className).not.toContain('grid-cols-2')
  })

  it('draws no row, no column and no section for what is hidden', () => {
    const fields = [f('a'), f('b', { show_when: { match: 'all', conditions: [{ field: 'a', op: 'equals', value: 'x' }] } })]
    render(<FormRenderer form={make(fields, [[['a']], [['b']]])} preview />)

    expect(screen.getByTestId('row-r0')).toBeInTheDocument()
    expect(screen.queryByTestId('row-r1')).toBeNull()
  })
})

describe('the matrix', () => {
  it('is one labelled group for each row, with an accessible name for every cell, and keeps what was chosen', async () => {
    const user = userEvent.setup()
    render(<FormRenderer form={make([MATRIX], [[['priorities']]])} preview />)

    const row = screen.getByTestId('matrix-row-priorities-location')
    expect(row).toHaveAttribute('role', 'radiogroup')
    expect(within(row).getByLabelText('الموقع: مهم جدًا')).toBeInTheDocument()

    await user.click(screen.getByTestId('matrix-cell-priorities-location-high'))
    await user.click(screen.getByTestId('matrix-cell-priorities-price-low'))
    expect(screen.getByTestId('matrix-cell-priorities-location-high')).toBeChecked()
    expect(screen.getByTestId('matrix-cell-priorities-price-low')).toBeChecked()
    expect(screen.getByTestId('matrix-cell-priorities-location-low')).not.toBeChecked()
  })

  it('asks for every row when it says so, and says it beside the matrix', async () => {
    const user = userEvent.setup()
    render(<FormRenderer form={make([MATRIX], [[['priorities']]])} preview />)
    await user.click(screen.getByTestId('matrix-cell-priorities-location-high'))
    await user.click(screen.getByTestId('landing-submit'))

    expect(await screen.findByTestId('error-priorities')).toBeInTheDocument()
  })

  it('takes several answers for a row when it allows many', async () => {
    const user = userEvent.setup()
    const many = { ...MATRIX, matrix: { ...MATRIX.matrix!, multiple: true, require_all_rows: false } }
    render(<FormRenderer form={make([many], [[['priorities']]])} preview />)
    await user.click(screen.getByTestId('matrix-cell-priorities-location-low'))
    await user.click(screen.getByTestId('matrix-cell-priorities-location-high'))

    expect(screen.getByTestId('matrix-cell-priorities-location-low')).toBeChecked()
    expect(screen.getByTestId('matrix-cell-priorities-location-high')).toBeChecked()
    expect(screen.getByTestId('matrix-row-priorities-location')).toHaveAttribute('role', 'group')
  })
})

describe('the repeater', () => {
  it('starts with its default rows, adds up to its maximum and removes a row', async () => {
    const user = userEvent.setup()
    render(<FormRenderer form={make([REPEATER], [[['guests']]])} preview />)

    expect(screen.getByTestId('repeater-row-guests-0')).toBeInTheDocument()
    expect(screen.queryByTestId('repeater-row-guests-1')).toBeNull()
    await user.click(screen.getByTestId('repeater-add-guests'))
    expect(screen.getByTestId('repeater-row-guests-1')).toBeInTheDocument()
    expect(screen.queryByTestId('repeater-add-guests')).toBeNull()

    await user.click(screen.getByTestId('repeater-remove-guests-1'))
    expect(screen.queryByTestId('repeater-row-guests-1')).toBeNull()
  })

  it('names the row and the column that is wrong', async () => {
    const user = userEvent.setup()
    render(<FormRenderer form={make([REPEATER], [[['guests']]])} preview />)
    const row = screen.getByTestId('repeater-row-guests-0')
    await user.type(within(row).getByLabelText(/^الجوال/, { selector: 'input' }), '12')
    await user.click(screen.getByTestId('landing-submit'))

    expect(within(row).getAllByRole('alert').length).toBeGreaterThan(0)
  })
})

describe('validation in the visitor\'s own words', () => {
  it('uses the manager\'s message for a failed pattern, and keeps what was typed', async () => {
    const user = userEvent.setup()
    const plot = f('plot', { label: 'رقم القطعة', validation: { pattern: '^[A-Z]{2}\\d{4}$', message: 'مثال: AB1234' } })
    render(<FormRenderer form={make([plot], [[['plot']]])} preview />)
    await user.type(screen.getByTestId('input-plot'), 'zz')
    await user.click(screen.getByTestId('landing-submit'))

    expect(await screen.findByTestId('error-plot')).toHaveTextContent('مثال: AB1234')
    expect(screen.getByTestId('input-plot')).toHaveValue('zz')
    expect(screen.getByTestId('input-plot')).toHaveAttribute('aria-invalid', 'true')
    expect(screen.getByTestId('input-plot')).toHaveAttribute('aria-describedby', expect.stringContaining('-err'))
  })
})

describe('the phone', () => {
  it('offers the countries, starts in the form\'s own, and sends the number with its country', async () => {
    const user = userEvent.setup()
    const onSubmit = vi.fn().mockResolvedValue(undefined)
    render(<FormRenderer form={make([f('mobile', { type: 'phone', label: 'الجوال', required: true })], [[['mobile']]], { settings: { ...DEFAULT_SETTINGS, mode: 'single', default_country: 'AE' } })} onSubmit={onSubmit} />)

    expect(screen.getByTestId('country-mobile')).toHaveValue('AE')
    await user.selectOptions(screen.getByTestId('country-mobile'), 'KW')
    await user.type(screen.getByTestId('input-mobile'), '55123456')
    await user.click(screen.getByTestId('landing-submit'))

    expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({ mobile: { country: 'KW', number: '55123456' } }), false)
  })

  it('refuses a number that is not one', async () => {
    const user = userEvent.setup()
    render(<FormRenderer form={make([f('mobile', { type: 'phone', label: 'الجوال', required: true })], [[['mobile']]])} onSubmit={vi.fn()} />)
    await user.type(screen.getByTestId('input-mobile'), '12')
    await user.click(screen.getByTestId('landing-submit'))

    expect(await screen.findByTestId('error-mobile')).toBeInTheDocument()
  })
})

describe('the header', () => {
  const h = (layoutName: string, extra: Partial<typeof DEFAULT_DESIGN.header> = {}) => make([f('a')], [[['a']]], { design: { ...DEFAULT_DESIGN, header: { ...DEFAULT_DESIGN.header, layout: layoutName as never, ...extra } }, logo_url: '/api/v1/public/form-assets/x.png', image_url: 'https://x.test/h.jpg', benefits: ['ميزة'] })

  it.each(['minimal', 'centered', 'split', 'cover', 'project_card'])('%s keeps every part: the headline, the project and the selling points', (name) => {
    render(<FormRenderer form={h(name, { location: 'الرياض', price_from: 900000, trust_note: 'بياناتك آمنة' })} preview />)

    expect(screen.getByTestId('form-header')).toHaveAttribute('data-layout', name)
    expect(screen.getByTestId('landing-headline')).toHaveTextContent('العنوان')
    expect(screen.getByTestId('landing-benefits')).toHaveTextContent('ميزة')
    expect(screen.getByTestId('form-facts')).toHaveTextContent('الرياض')
    expect(screen.getByTestId('form-trust')).toHaveTextContent('بياناتك آمنة')
  })

  it('shows the logo, or not, as asked', () => {
    const { rerender } = render(<FormRenderer form={h('centered')} preview />)
    expect(screen.getByTestId('form-logo')).toHaveAttribute('src', '/api/v1/public/form-assets/x.png')

    rerender(<FormRenderer form={h('centered', { show_logo: false })} preview />)
    expect(screen.queryByTestId('form-logo')).toBeNull()
  })
})

describe('design', () => {
  it('takes colour, radius, spacing and input size from closed lists and nothing else', () => {
    render(<FormRenderer form={make([f('a')], [[['a']]], { design: { ...DEFAULT_DESIGN, style: { ...DEFAULT_DESIGN.style, primary: 'blue', radius: 'xl', input_size: 'large', field_spacing: 'loose' } } })} preview />)
    const root = screen.getByTestId('form-root')

    expect(root.style.getPropertyValue('--f-primary')).toBe('#1D4ED8')
    expect(root.style.getPropertyValue('--f-radius')).toBe('22px')
    expect(root.style.getPropertyValue('--f-input-h')).toBe('3.5rem')
    expect(root.style.getPropertyValue('--f-gap')).toBe('1.5rem')
  })

  it('a token it does not know falls back to the default, never to the string it was given', () => {
    render(<FormRenderer form={make([f('a')], [[['a']]], { design: { ...DEFAULT_DESIGN, style: { ...DEFAULT_DESIGN.style, primary: 'red; background:url(//evil)' as never } } })} preview />)

    expect(screen.getByTestId('form-root').style.getPropertyValue('--f-primary')).toBe('#0F766E')
  })
})

describe('preview', () => {
  it('sends nothing and records nothing', async () => {
    const user = userEvent.setup()
    const onSubmit = vi.fn()
    const events = { onStart: vi.fn(), onStep: vi.fn(), onProgress: vi.fn(), onAttempt: vi.fn() }
    render(<FormRenderer form={make([f('a', { required: false })], [[['a']]])} preview events={events} onSubmit={onSubmit} />)
    await user.type(screen.getByTestId('input-a'), 'x')
    await user.click(screen.getByTestId('landing-submit'))

    expect(onSubmit).not.toHaveBeenCalled()
    expect(events.onStart).not.toHaveBeenCalled()
    expect(events.onProgress).not.toHaveBeenCalled()
  })
})

describe('accessibility', () => {
  it('labels every control, marks required ones, and joins an error to its field', async () => {
    const user = userEvent.setup()
    render(<FormRenderer form={make([f('name', { label: 'الاسم', required: true, help: 'كما في الهوية' })], [[['name']]])} />)

    const input = screen.getByLabelText(/الاسم/)
    expect(input).toHaveAttribute('aria-required', 'true')
    expect(input.getAttribute('aria-describedby')).toContain('-help')
    await user.click(screen.getByTestId('landing-submit'))
    expect(screen.getByTestId('input-name').getAttribute('aria-describedby')).toContain('-err')
    expect(screen.getByRole('alert')).toBeInTheDocument()
  })

  it('moves focus to the first field with a problem', async () => {
    const user = userEvent.setup()
    render(<FormRenderer form={make([f('a', { required: true }), f('b', { required: true })], [[['a']], [['b']]])} />)
    await user.click(screen.getByTestId('landing-submit'))

    await vi.waitFor(() => expect(document.activeElement).toBe(screen.getByTestId('input-a')))
  })

  it('has a visible name for the form and for each group of choices', () => {
    render(<FormRenderer form={make([f('purpose', { type: 'radio', label: 'الغرض', options: [{ value: 'a', label: 'أ' }, { value: 'b', label: 'ب' }] })], [[['purpose']]])} preview />)

    expect(screen.getByRole('group', { name: /الغرض/ })).toBeInTheDocument()
    expect(screen.getByRole('form', { name: 'العنوان' })).toBeInTheDocument()
  })
})

describe('the thank-you', () => {
  it('offers the actions the workspace chose, each with a safe address', () => {
    render(<ThankYou title="شكرًا" message="تم" design={DEFAULT_DESIGN} preview redirectUrl={null} redirectDelay={0} actions={[
      { type: 'whatsapp', label: 'واتساب', value: '+966551234567' }, { type: 'call', label: 'اتصل', value: '0551234567' }, { type: 'brochure', label: 'الكتيّب', value: 'https://x.test/b.pdf' }, { type: 'project', label: 'خطر', value: 'javascript:alert(1)' },
    ]} />)

    expect(screen.getByTestId('thanks-action-whatsapp')).toHaveAttribute('href', 'https://wa.me/966551234567')
    expect(screen.getByTestId('thanks-action-call')).toHaveAttribute('href', 'tel:+0551234567')
    expect(screen.getByTestId('thanks-action-brochure')).toHaveAttribute('href', 'https://x.test/b.pdf')
    expect(screen.queryByTestId('thanks-action-project'), 'an address that is not https is never a link').toBeNull()
  })

  it('computes addresses only from what is safe', () => {
    expect(actionHref({ type: 'whatsapp', label: 'x', value: 'hello' })).toBeNull()
    expect(actionHref({ type: 'appointment', label: 'x', value: 'http://plain.test' })).toBeNull()
  })

  it('does not redirect in preview', () => {
    vi.useFakeTimers()
    const assign = vi.fn()
    vi.stubGlobal('location', { ...window.location, assign })
    render(<ThankYou title={null} message={null} actions={[]} redirectUrl="https://x.test" redirectDelay={1} design={DEFAULT_DESIGN} preview />)
    vi.advanceTimersByTime(2000)
    expect(assign).not.toHaveBeenCalled()
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })
})

void fireEvent
