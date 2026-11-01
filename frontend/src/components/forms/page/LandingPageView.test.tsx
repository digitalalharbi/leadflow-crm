import { fireEvent, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { DEFAULT_DESIGN, type LandingPageConfig } from '@/features/hub'
import { renderWithProviders } from '@/test/utils'
import { LandingPageView, whatsappHref, whatsappMessage, type PageContent } from './LandingPageView'

const page = (patch: Partial<LandingPageConfig> = {}, cta: Partial<LandingPageConfig['cta']> = {}): LandingPageConfig => ({
  template: 'real_estate_lead', developer_name: null, developer_logo_url: null,
  facts: [{ key: 'location', value: 'الرياض' }],
  cta: { form: { enabled: true }, whatsapp: { enabled: true, number: '+966500000000', message: null }, phone: { enabled: true, number: '+966500000001' }, primary: 'form', secondary: null, floating_whatsapp: false, floating_call: false, ...cta },
  auto_steps: true, seo: { title: null, description: null, og_image: null, index: false }, ...patch,
})

const content = (config: LandingPageConfig): PageContent => ({ page: config, design: DEFAULT_DESIGN, headline: 'بيتك في رواق', description: 'وصف قصير', benefits: ['موقع مميز'], logo: null, image: null, project: 'رواق', cta_label: 'سجل', ref: 'ABC234' })

describe('a campaign page', () => {
  it('says who, what and how to act, and keeps the form on the page', () => {
    renderWithProviders(<LandingPageView content={content(page())} form={<p data-testid="the-form" />} />)

    expect(screen.getByTestId('landing-headline')).toHaveTextContent('بيتك في رواق')
    expect(screen.getByTestId('page-fact-location')).toHaveTextContent('الرياض')
    expect(screen.getByTestId('the-form')).toBeInTheDocument()
  })

  it('puts the reference in the WhatsApp message and reports each tap', () => {
    const onCta = vi.fn()
    renderWithProviders(<LandingPageView content={content(page())} form={null} onCta={onCta} />)
    const link = screen.getByTestId('page-hero-whatsapp')

    expect(decodeURIComponent(link.getAttribute('href') ?? '')).toContain('Ref: ABC234')
    expect(link.getAttribute('href')).toContain('https://wa.me/966500000000')
    fireEvent.click(link)
    expect(onCta).toHaveBeenCalledWith('whatsapp', 'hero')

    const call = screen.getByTestId('page-hero-phone')
    expect(call.getAttribute('href')).toBe('tel:+966500000001')
    fireEvent.click(call)
    expect(onCta).toHaveBeenCalledWith('phone', 'hero')
  })

  it('hides what is switched off, and floats only what was asked to', () => {
    renderWithProviders(<LandingPageView content={content(page({}, { form: { enabled: false }, whatsapp: { enabled: true, number: '+966500000000', message: null }, phone: { enabled: false, number: null }, primary: 'whatsapp', floating_whatsapp: true }))} form={<p data-testid="the-form" />} />)

    expect(screen.queryByTestId('the-form')).toBeNull()
    expect(screen.queryByTestId('page-hero-phone')).toBeNull()
    expect(screen.getByTestId('page-floating-whatsapp')).toBeInTheDocument()
  })

  it('the minimal template leaves out the facts, and a preview goes nowhere and floats nothing', () => {
    renderWithProviders(<LandingPageView preview content={content(page({ template: 'minimal_lead' }, { floating_call: true }))} form={null} />)

    expect(screen.queryByTestId('page-facts')).toBeNull()
    expect(screen.queryByTestId('page-floating')).toBeNull()
    expect(screen.getByTestId('page-hero-phone').getAttribute('href')).toBeNull()
  })

  it('a custom message still carries the reference; an escaped link stays one link', () => {
    expect(whatsappMessage('رواق', 'أريد التفاصيل', 'XY2345', 'x')).toBe('أريد التفاصيل — Ref: XY2345')
    expect(whatsappMessage('رواق', null, undefined, 'مهتم {{project}}')).toBe('مهتم رواق')
    expect(whatsappHref('+966500000000', 'a&b=c')).toBe('https://wa.me/966500000000?text=a%26b%3Dc')
  })
})
